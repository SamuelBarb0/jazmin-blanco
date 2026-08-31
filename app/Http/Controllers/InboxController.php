<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bandeja de conversaciones de WhatsApp.
 *
 * Le da a la doctora lo que antes no existía: ver los chats reales, pausar al
 * asistente en uno concreto y responder ella misma por el número del
 * consultorio. Mientras un chat está en pausa, el asistente guarda los mensajes
 * entrantes pero no contesta (ver ProcessWhatsAppMessage).
 */
class InboxController extends Controller
{
    /**
     * Cuántos mensajes se le mandan al navegador por conversación.
     *
     * No es paginación de verdad: es un techo para que un chat largo no
     * multiplique el peso de cada refresco. Lo anterior sigue en la base y en
     * el celular de la paciente; si algún día hace falta consultarlo desde el
     * CRM, esto se convierte en "cargar mensajes anteriores".
     */
    private const MAX_MENSAJES = 100;

    /**
     * Cuántas conversaciones se le mandan al navegador sin buscar.
     *
     * La lista entera son 332 chats y 242 KB de JSON, y la bandeja la reenvía
     * COMPLETA cada 5 segundos: unos 170 MB por hora de pestaña abierta, que en
     * el celular de la doctora se pagan en datos. Es el mismo problema que ya se
     * arregló en el panel del chat con `MAX_MENSAJES`; la lista se quedó sin
     * recortar y encima crece con cada paciente nueva.
     *
     * Lo que no cabe no se pierde: el buscador sigue mirando las 332, y las que
     * piden atención entran siempre (ver `recortar()`).
     */
    private const MAX_LISTA = 50;

    public function index(Request $request, ?Conversation $conversation = null): Response
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));

        $conversations = $user->conversations()
            // Va ANTES de los withMax: `select()` reemplaza la lista de
            // columnas, así que ponerlo después borraría sus agregados.
            ->select('conversations.*')
            ->where('channel', '!=', 'panel')
            ->with(['lead:id,name,phone'])
            ->withMax('messages as last_message_at', 'created_at')
            // El id del último mensaje deja que el frontend sepa si el chat
            // abierto cambió sin recargarlo entero. Es un entero, así que la
            // comparación no depende de formatos de fecha ni de zonas horarias.
            ->withMax('messages as last_message_id', 'id')
            // La vista previa iba con una consulta POR conversación. Con la
            // bandeja refrescando cada 5 segundos ese N+1 escalaba fatal: 200
            // chats serían 200 consultas cada 5 segundos. Ahora es una sola.
            ->addSelect(['preview' => Message::select('content')
                ->whereColumn('conversation_id', 'conversations.id')
                ->latest('id')
                ->limit(1),
            ])
            // Quién habló de último. Con esto la bandeja puede señalar los
            // chats donde la paciente escribió y NADIE le contestó, que es la
            // situación que se coló durante días sin que nadie la viera.
            ->addSelect(['last_role' => Message::select('role')
                ->whereColumn('conversation_id', 'conversations.id')
                ->latest('id')
                ->limit(1),
            ])
            ->orderByDesc('last_message_at')
            ->get();

        $total = $conversations->count();

        $conversations = $q !== ''
            ? $this->buscar($conversations, $q)
            : $this->recortar($conversations);

        $conversations = $conversations
            ->map(fn (Conversation $c) => [
                'id' => $c->id,
                'title' => $c->title ?: ($c->lead?->name ?: 'Sin nombre'),
                'lead' => $c->lead ? ['id' => $c->lead->id, 'name' => $c->lead->name, 'phone' => $c->lead->phone] : null,
                'channel' => $c->channel,
                'bot_enabled' => $c->bot_enabled,
                'needs_human' => $c->needsHuman(),
                'last_message_at' => $c->last_message_at,
                'last_message_id' => $c->last_message_id,
                // La fila la recorta el CSS a una línea, así que mandar el
                // mensaje entero es pagar datos por texto que nadie ve. Con 332
                // chats, esto solo es la mitad del peso de la lista.
                'preview' => $c->preview ? Str::limit($c->preview, 120) : null,
                // Con el asistente activo esto dura los segundos que tarda en
                // responder, así que solo se marca donde está en pausa: ahí es
                // donde el mensaje se queda esperando de verdad.
                'sin_responder' => ! $c->bot_enabled && $c->last_role === 'user',
            ]);

        // Por defecto se abre la conversación más reciente, que en pantalla
        // grande evita el panel vacío. En celular NO sirve: como solo cabe una
        // columna, abrir un chat solo deja la lista inalcanzable y el botón de
        // volver rebotaría al mismo chat. Por eso el volver pide `?lista=1`.
        if ($conversation?->exists && ! $user->esDeSuCuenta($conversation)) {
            abort(403);
        }
        $selected = $conversation?->exists
            ? $conversation
            // Buscando NO se abre nada solo: el relleno de escritorio traería
            // el chat más reciente de la clínica, que casi nunca es el que se
            // está buscando, y taparía el resultado con otra conversación.
            : ($request->boolean('lista') || $q !== ''
                ? null
                : $this->relleno($user, (int) $request->query('abierta', 0)));

        return Inertia::render('inbox/index', [
            'conversations' => $conversations,
            'q' => $q,
            'total' => $total,
            'selected' => $selected ? $this->serialize($selected) : null,
            // El servidor no sabe el tamaño de la pantalla, así que en vez de
            // decidir por el viewport le dice al front CÓMO se eligió el chat:
            // si lo abrió el usuario o si es el relleno de escritorio. En
            // celular ese relleno se oculta por CSS y se ve la lista.
            'auto_selected' => $selected !== null && ! $conversation?->exists,
        ]);
    }

    /**
     * El chat que se abre solo en pantalla grande para no dejar el panel vacío.
     *
     * `$abierta` es el que el navegador YA tiene delante. Sin ese dato, cada
     * recarga del chat volvía a elegir «el más reciente de la clínica»: si
     * mientras tanto escribía otra paciente, el panel se le cambiaba debajo a la
     * doctora, que podía acabar leyendo —o escribiendo— en la conversación
     * equivocada. Solo pasaba con el relleno, porque al abrir un chat a mano el
     * id va en la URL y ya no hay nada que adivinar.
     */
    private function relleno(User $user, int $abierta): ?Conversation
    {
        $deLaClinica = fn () => $user->conversations()->where('channel', '!=', 'panel');

        return ($abierta > 0 ? $deLaClinica()->whereKey($abierta)->first() : null)
            ?? $deLaClinica()
                ->withMax('messages as last_message_at', 'created_at')
                ->orderByDesc('last_message_at')
                ->first();
    }

    /**
     * Las más recientes, más TODAS las que piden atención.
     *
     * Recortar a secas escondería justo lo que hay que ver: un chat escalado o
     * una paciente sin responder puede llevar días quieto y caer en la posición
     * 80. Y los dos contadores de la cabecera se calculan en el navegador sobre
     * lo que le llega, así que truncar la lista los dejaría mintiendo.
     *
     * @param  Collection<int,Conversation>  $conversations  Ya ordenadas por actividad.
     * @return Collection<int,Conversation>
     */
    private function recortar(Collection $conversations): Collection
    {
        $recientes = $conversations->take(self::MAX_LISTA)->pluck('id')->flip();

        return $conversations
            ->filter(fn (Conversation $c) => $recientes->has($c->id)
                || $c->needsHuman()
                || (! $c->bot_enabled && $c->last_role === 'user'))
            ->values();
    }

    /**
     * Filtra la bandeja por nombre, teléfono o texto de los mensajes.
     *
     * Existe porque la lista solo se podía recorrer en orden de última
     * actividad: con 300 chats, encontrar a una paciente concreta —o el número
     * desde el que escribió— era bajar a ciegas, y la doctora acababa
     * buscándola en su celular en vez de en el CRM.
     *
     * Se filtra en PHP y no en SQL por lo mismo que en el resto del sistema: el
     * teléfono está guardado de cualquier forma (con indicativo y sin él, con
     * espacios), así que un `like` sobre la columna se pierde la mitad. El
     * texto de los mensajes sí va en SQL, que ahí sí son decenas de miles de
     * filas y no tiene sentido traerlas.
     *
     * @param  Collection<int,Conversation>  $conversations
     * @return Collection<int,Conversation>
     */
    private function buscar(Collection $conversations, string $q): Collection
    {
        $aguja = $this->normalizar($q);
        $digitos = $this->soloDigitos($q);

        // Con una o dos letras coincide medio listado y el buscador no ayuda;
        // el filtro por nombre sí se hace desde la primera, que es lo que se
        // espera al teclear.
        $porTexto = mb_strlen($aguja) >= 3
            ? Message::whereIn('conversation_id', $conversations->pluck('id'))
                ->where('content', 'like', '%'.$q.'%')
                ->distinct()
                ->pluck('conversation_id')
                ->flip()
            : collect();

        return $conversations->filter(function (Conversation $c) use ($aguja, $digitos, $porTexto) {
            if ($aguja !== '' && str_contains($this->normalizar($c->title.' '.$c->lead?->name), $aguja)) {
                return true;
            }

            // Se comparan solo los dígitos y recortados a los últimos diez —el
            // mismo criterio que `Settings::phoneInList()`—, porque en
            // producción el mismo número está guardado a diez cifras y con el
            // 57 delante. Así encontrarla escribiendo los últimos cuatro, el
            // número a diez cifras o el completo con indicativo da igual.
            $telefono = $this->soloDigitos((string) $c->lead?->phone);
            if (strlen($digitos) >= 3 && $telefono !== '' && str_contains($telefono, $digitos)) {
                return true;
            }

            return $porTexto->has($c->id);
        })->values();
    }

    /**
     * Los dígitos de un teléfono, recortados a los últimos diez.
     *
     * El indicativo sobra para comparar: `573001112233` y `3001112233` son la
     * misma paciente, y en la base están las dos formas.
     */
    private function soloDigitos(string $texto): string
    {
        $digitos = (string) preg_replace('/\D/', '', $texto);

        return strlen($digitos) > 10 ? substr($digitos, -10) : $digitos;
    }

    /** Minúsculas y sin tildes, para que «Jazmín» y «jazmin» sean lo mismo. */
    private function normalizar(?string $texto): string
    {
        return Str::lower(Str::ascii(trim((string) $texto)));
    }

    /** Pausa o reanuda al asistente en una conversación. */
    public function toggleBot(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorizeConversation($request, $conversation);

        $activar = ! $conversation->bot_enabled;

        $conversation->forceFill([
            'bot_enabled' => $activar,
            'bot_paused_at' => $activar ? null : now(),
            // El botón es una DECISIÓN: esta pausa no caduca sola, a diferencia
            // de la que se pone al escribir a mano. Ver `debeReanudarAlAsistente()`.
            'bot_paused_manually' => ! $activar,
            // Tocar el interruptor ya es haberse enterado del chat: la alerta de
            // "esperando a una persona" se apaga.
            'escalated_at' => null,
            'escalation_reason' => null,
        ])->save();

        return back()->with('success', $activar
            ? 'El asistente vuelve a responder en este chat.'
            : 'Asistente pausado. A partir de ahora respondes tú en este chat.');
    }

    /** Envía un mensaje escrito por la doctora al número de la paciente. */
    public function send(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorizeConversation($request, $conversation);

        // El tope depende del tipo: WhatsApp admite 16 MB en video pero solo 5
        // en imagen. El `max:15360` que había aquí para todo dejaba pasar fotos
        // de 8 MB que Meta rechaza después con `131053`, cuando la doctora ya
        // las ve enviadas en la bandeja.
        $subido = $request->file('archivo');
        $tipo = $subido && Str::startsWith((string) $subido->getMimeType(), 'video/') ? 'video' : 'image';

        $data = $request->validate([
            'content' => ['nullable', 'string', 'max:4000'],
            'archivo' => ['nullable', 'file', 'max:'.intdiv(WhatsAppService::limiteBytes($tipo), 1024),
                'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/3gpp'],
        ], [
            'archivo.max' => sprintf(
                'WhatsApp no acepta %s de más de %d MB. Comprime el archivo antes de enviarlo.',
                $tipo === 'video' ? 'videos' : 'imágenes',
                WhatsAppService::limiteMb($tipo),
            ),
        ]);

        $texto = trim((string) ($data['content'] ?? ''));
        $adjunto = $request->file('archivo');

        if ($texto === '' && ! $adjunto) {
            return back()->with('error', 'Escribe un mensaje o adjunta una imagen.');
        }

        $telefono = $conversation->lead?->phone;
        if (blank($telefono)) {
            return back()->with('error', 'Esta paciente no tiene teléfono registrado, no se le puede escribir.');
        }

        if (! $conversation->windowIsOpen()) {
            return back()->with('error', 'Pasaron más de 24 horas desde el último mensaje de la paciente. WhatsApp no permite escribirle texto libre hasta que ella vuelva a escribir.');
        }

        $whatsapp = WhatsAppService::fromConfig();
        $media = [];

        if ($adjunto) {
            $ruta = $adjunto->store('whatsapp/'.$conversation->id, 'public');
            $url = Storage::disk('public')->url($ruta);

            // WhatsApp descarga el archivo por HTTP desde sus servidores, así
            // que la URL tiene que ser absoluta y alcanzable desde fuera.
            if (! Str::startsWith($url, ['http://', 'https://'])) {
                $url = url($url);
            }

            $esVideo = Str::startsWith((string) $adjunto->getMimeType(), 'video/');

            // El texto viaja como pie de la imagen: así llega un solo mensaje.
            $enviado = $whatsapp->sendMedia($telefono, $esVideo ? 'video' : 'image', $url, $texto);

            $media[] = [
                'type' => $esVideo ? 'video' : 'image',
                'url' => Storage::disk('public')->url($ruta),
                'caption' => $texto,
            ];
        } else {
            $enviado = $whatsapp->sendText($telefono, $texto);
        }

        if (! $enviado) {
            return back()->with('error', 'No se pudo enviar el mensaje por WhatsApp. Revisa la conexión del número.');
        }

        $conversation->messages()->create([
            // Se guarda como 'assistant' para que, al reactivar al asistente,
            // este entienda que ese mensaje salió de nuestro lado.
            'role' => 'assistant',
            'sent_by' => 'human',
            // Con adjunto y sin texto, se deja constancia de qué se mandó para
            // que el asistente no lea un mensaje vacío al retomar el hilo.
            'content' => $texto !== '' ? $texto : '[La doctora envió un archivo.]',
            'media' => $media ?: null,
        ]);

        // Escribir a mano implica tomar el control: si el asistente seguía
        // activo, se pausa solo para que no responda encima. Y si el chat estaba
        // esperando a una persona, ya la tuvo: se apaga la alerta.
        if ($conversation->bot_enabled) {
            // Pausa automática, no decidida: caduca cuando el chat lleve horas
            // quieto, para que la paciente no se quede sin respuesta la próxima
            // vez que escriba. La del botón sí se queda puesta.
            $conversation->forceFill([
                'bot_enabled' => false,
                'bot_paused_at' => now(),
                'bot_paused_manually' => false,
            ])->save();
        }

        if ($conversation->needsHuman()) {
            $conversation->forceFill(['escalated_at' => null, 'escalation_reason' => null])->save();
        }

        return back();
    }

    /**
     * @return array<string,mixed>
     */
    private function serialize(Conversation $conversation): array
    {
        $conversation->load('lead:id,name,phone');
        $ultimoEntrante = $conversation->lastInboundAt();

        // Un chat largo se reenviaba ENTERO en cada refresco. A 5 segundos y
        // desde el celular de la doctora eso es un desperdicio de datos que
        // además crece sin techo, así que se manda solo el tramo reciente.
        $total = $conversation->messages()->count();
        $mensajes = $conversation->messages()
            ->latest('id')
            ->take(self::MAX_MENSAJES)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'sent_by' => $m->sent_by,
                'content' => $m->content,
                'media' => $m->media,
                'created_at' => $m->created_at?->toIso8601String(),
            ]);

        return [
            'id' => $conversation->id,
            'title' => $conversation->title ?: ($conversation->lead?->name ?: 'Sin nombre'),
            'channel' => $conversation->channel,
            'bot_enabled' => $conversation->bot_enabled,
            'bot_paused_at' => $conversation->bot_paused_at?->toIso8601String(),
            'needs_human' => $conversation->needsHuman(),
            'escalated_at' => $conversation->escalated_at?->toIso8601String(),
            'escalation_reason' => $conversation->escalation_reason,
            'lead' => $conversation->lead ? [
                'id' => $conversation->lead->id,
                'name' => $conversation->lead->name,
                'phone' => $conversation->lead->phone,
            ] : null,
            'window_open' => $conversation->windowIsOpen(),
            'window_closes_at' => $ultimoEntrante?->copy()->addDay()->toIso8601String(),
            'messages' => $mensajes,
            'older_count' => max(0, $total - $mensajes->count()),
        ];
    }

    private function authorizeConversation(Request $request, Conversation $conversation): void
    {
        abort_unless($request->user()->esDeSuCuenta($conversation), 403);
    }
}
