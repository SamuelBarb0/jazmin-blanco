<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\WhatsAppService;
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

    public function index(Request $request, ?Conversation $conversation = null): Response
    {
        $user = $request->user();

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
            ->orderByDesc('last_message_at')
            ->get()
            ->map(fn (Conversation $c) => [
                'id' => $c->id,
                'title' => $c->title ?: ($c->lead?->name ?: 'Sin nombre'),
                'lead' => $c->lead ? ['id' => $c->lead->id, 'name' => $c->lead->name, 'phone' => $c->lead->phone] : null,
                'channel' => $c->channel,
                'bot_enabled' => $c->bot_enabled,
                'needs_human' => $c->needsHuman(),
                'last_message_at' => $c->last_message_at,
                'last_message_id' => $c->last_message_id,
                'preview' => $c->preview,
            ]);

        // Por defecto se abre la conversación más reciente, que en pantalla
        // grande evita el panel vacío. En celular NO sirve: como solo cabe una
        // columna, abrir un chat solo deja la lista inalcanzable y el botón de
        // volver rebotaría al mismo chat. Por eso el volver pide `?lista=1`.
        if ($conversation?->exists && $conversation->user_id !== $user->id) {
            abort(403);
        }
        $selected = $conversation?->exists
            ? $conversation
            : ($request->boolean('lista')
                ? null
                : $user->conversations()->where('channel', '!=', 'panel')
                    ->withMax('messages as last_message_at', 'created_at')
                    ->orderByDesc('last_message_at')
                    ->first());

        return Inertia::render('inbox/index', [
            'conversations' => $conversations,
            'selected' => $selected ? $this->serialize($selected) : null,
        ]);
    }

    /** Pausa o reanuda al asistente en una conversación. */
    public function toggleBot(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorizeConversation($request, $conversation);

        $activar = ! $conversation->bot_enabled;

        $conversation->forceFill([
            'bot_enabled' => $activar,
            'bot_paused_at' => $activar ? null : now(),
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

        $data = $request->validate([
            'content' => ['nullable', 'string', 'max:4000'],
            // WhatsApp acepta hasta 5 MB en imagen y 16 MB en video; nos
            // quedamos en 15 MB y dejamos que Meta rechace el borde.
            'archivo' => ['nullable', 'file', 'max:15360',
                'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/3gpp'],
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
            $conversation->forceFill(['bot_enabled' => false, 'bot_paused_at' => now()])->save();
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
        abort_unless($conversation->user_id === $request->user()->id, 403);
    }
}
