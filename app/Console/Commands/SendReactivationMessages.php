<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\ReminderOptOut;
use App\Models\User;
use App\Services\WhatsAppService;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Mensaje de reactivación a quien preguntó por WhatsApp y nunca agendó.
 *
 * Decisión de la doctora: alguien que escribió, recibió respuesta y se quedó
 * callado unas horas es un interés pagado (casi todos vienen de un anuncio) que
 * hoy se pierde sin que nadie lo toque.
 *
 * Sale SIEMPRE por plantilla aprobada, nunca como texto libre: la definición de
 * este mensaje es que la paciente lleva horas sin escribir, o sea que la ventana
 * de 24 h de WhatsApp está cerrada por construcción y el texto libre se
 * aceptaría con un 200 sin llegar nunca. La plantilla es MARKETING, no UTILITY:
 * no informa de ninguna cita existente, invita a agendar.
 *
 * Corre cada hora. Como los umbrales se miden contra "ahora", una corrida
 * perdida la recupera la siguiente en vez de saltarse a nadie.
 */
class SendReactivationMessages extends Command
{
    protected $signature = 'conversations:send-reactivation
                            {--dry-run : Muestra a quién se le escribiría, sin mandar nada}
                            {--force : Envía aunque sea fuera de la franja horaria decente}
                            {--limit= : Máximo de envíos en esta corrida (por defecto, el de la configuración)}
                            {--user= : Id o correo de la doctora (por defecto, todas)}';

    protected $description = 'Escribe por WhatsApp a quien preguntó y nunca agendó, tras un periodo de silencio';

    /**
     * Cuerpo de la plantilla `reactivacion_lead`, palabra por palabra.
     *
     * No lleva ningún {{n}}: el texto que aprobó Meta es idéntico para todas.
     * Se guarda aquí para dejar en el historial del chat exactamente lo que le
     * llegó a la paciente — si esto y la plantilla se separan, la doctora lee en
     * el CRM algo distinto de lo que la paciente recibió.
     */
    private const PLANTILLA = "¡Hola! 😊\n\n"
        ."Esperamos que te encuentres muy bien.\n\n"
        ."Hace un tiempo recibimos tu interés en nuestros servicios y queríamos saber si aún podemos ayudarte.\n\n"
        ."Si todavía estás buscando información o deseas dar el siguiente paso, con gusto podemos orientarte, resolver tus dudas y ayudarte a agendar una valoración con la Dra. Jasmin Blanco, para brindarte una atención personalizada según tus necesidades.\n\n"
        ."Si en este momento no es el momento adecuado, no te preocupes. Estaremos encantados de atenderte cuando decidas hacerlo.\n\n"
        .'Quedamos atentos. Será un gusto acompañarte en tu proceso. ✨';

    /** Franja en la que es aceptable escribirle a una paciente (hora del consultorio). */
    private const HORA_DESDE = 8;

    private const HORA_HASTA = 20;

    /**
     * Último mensaje ENTRANTE de cada conversación, id => Carbon.
     *
     * Se calcula de una vez en `candidatas()` con una sola consulta agrupada.
     * Antes salía de `Conversation::lastInboundAt()`, que hace un MAX por
     * conversación: entre filtrar, ordenar y luego imprimir el silencio, eso
     * eran tres consultas por chat en cada corrida horaria.
     *
     * @var array<int,Carbon>
     */
    private array $ultimoInbound = [];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $config = Settings::reactivationConfig();

        if (! $config['enabled'] && ! $dry) {
            $this->warn('La reactivación está desactivada en la configuración (`reactivation_enabled`).');

            return self::SUCCESS;
        }

        // El interruptor general apaga TODO lo automático, igual que en los
        // recordatorios. En simulación no frena: sirve para ver a quién se le
        // escribiría sin escribirle a nadie.
        if (! $dry && ! Settings::whatsappBotEnabled()) {
            $this->warn('El bot de WhatsApp está apagado; no se envía ninguna reactivación.');

            return self::SUCCESS;
        }

        $whatsapp = WhatsAppService::fromConfig();
        if (! $whatsapp->isConfigured() && ! $dry) {
            $this->error('WhatsApp no está configurado (faltan WHATSAPP_ACCESS_TOKEN o WHATSAPP_PHONE_ID).');

            return self::FAILURE;
        }

        $tz = Settings::googleTimezone();
        $ahora = Carbon::now($tz);

        // Un mensaje comercial de madrugada es peor que no mandarlo: la corrida
        // de la mañana lo recupera igual.
        if (! $dry && ! $this->option('force') && ($ahora->hour < self::HORA_DESDE || $ahora->hour >= self::HORA_HASTA)) {
            $this->line("Fuera de la franja de envío ({$ahora->format('H:i')} en {$tz}). No se envía nada.");

            return self::SUCCESS;
        }

        $tope = (int) ($this->option('limit') ?: $config['max_per_run']);
        $codigoPais = Settings::reminderConfig()['country_code'];

        $enviados = 0;
        $fallidos = 0;
        $sinTelefono = 0;
        $excluidos = 0;
        $pendientes = 0;

        foreach ($this->users() as $user) {
            $candidatas = $this->candidatas($user, $config['hours'], $config['min_inbound_at']);
            $pendientes += $candidatas->count();

            foreach ($candidatas as $conversacion) {
                if ($enviados >= $tope) {
                    continue;
                }

                $telefono = Settings::phoneWithCountryCode($conversacion->lead?->phone, $codigoPais);

                if (! $telefono) {
                    $sinTelefono++;
                    $this->line('  <fg=yellow>sin teléfono</> '.($conversacion->lead?->name ?: "conversación #{$conversacion->id}"));

                    continue;
                }

                // Pidió que no le escribiéramos. Se reutiliza la lista de los
                // recordatorios a propósito: quien dijo "no me escriban" no lo
                // dijo solo para las citas, y tener dos listas separadas
                // significaría que darse de baja de una no sirve de nada.
                if (ReminderOptOut::has($user->id, $telefono)) {
                    $excluidos++;
                    $this->line("  <fg=gray>pidió no recibir mensajes</> {$telefono}");

                    continue;
                }

                if (! $dry && ! Settings::autoMessagingAllows($telefono)) {
                    $excluidos++;
                    $this->line("  <fg=gray>fuera de la lista de pruebas</> {$telefono}");

                    continue;
                }

                $silencio = (int) round($this->ultimoInbound[$conversacion->id]->diffInHours(now()));

                if ($dry) {
                    $this->line("  <fg=cyan>[{$silencio}h en silencio]</> ".($conversacion->lead?->name ?: '—')." · {$telefono}");
                    $enviados++;

                    continue;
                }

                try {
                    $ok = $whatsapp->sendTemplate($telefono, $config['template'], $config['language']);
                } catch (Throwable $e) {
                    $ok = false;
                    $this->error("  Error con {$telefono}: ".$e->getMessage());
                }

                if (! $ok) {
                    $fallidos++;
                    $this->line("  <fg=red>falló</> {$telefono}");

                    continue;
                }

                // La marca se pone SIEMPRE que el envío salió bien. Es lo único
                // que impide repetirle el mensaje a la misma persona en la
                // corrida de la hora siguiente, porque su silencio solo crece.
                $conversacion->forceFill(['reactivation_sent_at' => now()])->save();

                $this->registrarEnConversacion($conversacion);

                $enviados++;
                $this->line("  <fg=green>enviado</> ".($conversacion->lead?->name ?: '—')." · {$telefono} · {$silencio}h en silencio");
            }
        }

        $this->newLine();
        $restantes = max(0, $pendientes - $enviados - $fallidos - $sinTelefono - $excluidos);
        $desde = $config['min_inbound_at']
            ? ' · solo chats con actividad desde '.$config['min_inbound_at']->format('d/m/Y H:i')
            : ' · SIN fecha de corte (entra todo el histórico)';

        $this->info(($dry ? 'Simulación · ' : '')
            ."Reactivaciones: {$enviados} · fallidas: {$fallidos} · sin teléfono: {$sinTelefono} · excluidas: {$excluidos}"
            ." · umbral: {$config['hours']}h · tope: {$tope}".$desde);

        if ($restantes > 0) {
            $this->line("<fg=yellow>Quedan {$restantes} en cola por el tope; salen en las siguientes corridas.</>");
        }

        return self::SUCCESS;
    }

    /**
     * Conversaciones a las que toca escribirles.
     *
     * Los filtros baratos van en SQL y los que exigen mirar los mensajes se
     * resuelven después en memoria, sobre un conjunto ya pequeño.
     *
     * `$desde` es la línea de salida: por debajo de esa fecha la conversación ya
     * estaba fría cuando esto se encendió, y la doctora decidió empezar solo con
     * las nuevas. Sin ella, encenderlo suelta los 104 chats acumulados.
     *
     * @return Collection<int,Conversation>
     */
    private function candidatas(User $user, int $horas, ?Carbon $desde): Collection
    {
        $limite = now()->subHours($horas);

        /** @var Collection<int,Conversation> $conversaciones */
        $conversaciones = $user->conversations()
            ->whereNull('reactivation_sent_at')
            // Un chat escalado espera a que lo atienda una persona: meterle un
            // mensaje automático encima es justo lo que no toca.
            ->whereNull('escalated_at')
            // Solo WhatsApp: las de `panel` son pruebas de la doctora en el
            // playground, no pacientes. Contar sin filtrar infla todo — llegó a
            // haber 53 de playground contra 41 reales.
            ->where('channel', 'whatsapp')
            ->whereNotNull('lead_id')
            ->with('lead:id,name,phone')
            ->get();

        if ($conversaciones->isEmpty()) {
            return $conversaciones;
        }

        $conAgenda = $this->leadsQueYaAgendaron($user, $conversaciones->pluck('lead_id')->all());

        $this->ultimoInbound += Message::query()
            ->whereIn('conversation_id', $conversaciones->modelKeys())
            ->where('role', 'user')
            ->groupBy('conversation_id')
            ->selectRaw('conversation_id, MAX(created_at) as ultimo')
            ->pluck('ultimo', 'conversation_id')
            ->map(fn ($fecha) => Carbon::parse($fecha))
            ->all();

        return $conversaciones
            // Nunca agendó nada: es la condición que pidió la doctora.
            ->reject(fn (Conversation $c) => in_array($c->lead_id, $conAgenda, true))
            ->filter(function (Conversation $c) use ($limite, $desde) {
                $ultimo = $this->ultimoInbound[$c->id] ?? null;

                // Sin ningún mensaje SUYO no hay interés que reactivar: puede
                // ser una conversación creada por el CRM al agendar o importar.
                if ($ultimo === null || ! $ultimo->lt($limite)) {
                    return false;
                }

                // Ya estaba fría antes de encender esto: no es "cliente nuevo".
                return $desde === null || $ultimo->gte($desde);
            })
            // Los más recientes primero: un silencio de 2 días se recupera mucho
            // mejor que uno de 3 meses, y con tope por corrida el orden decide
            // quién entra hoy.
            ->sortByDesc(fn (Conversation $c) => $this->ultimoInbound[$c->id])
            ->values();
    }

    /**
     * Ids de lead que ya tienen alguna cita, por relación o por teléfono.
     *
     * Se mira también el teléfono porque hay citas importadas de la agenda de la
     * doctora que nunca se vincularon a un lead: sin ese cruce, a una paciente
     * que YA tiene cita se le mandaría un «¿aún podemos ayudarte?».
     *
     * @param  array<int,int|null>  $leadIds
     * @return array<int,int>
     */
    private function leadsQueYaAgendaron(User $user, array $leadIds): array
    {
        $ids = array_values(array_filter($leadIds));

        if ($ids === []) {
            return [];
        }

        $porRelacion = $user->appointments()
            ->whereIn('lead_id', $ids)
            ->pluck('lead_id')
            ->all();

        // Últimos 10 dígitos de todo teléfono que aparece en alguna cita.
        $telefonosConCita = $user->appointments()
            ->get(['patient_phone'])
            ->map(fn (Appointment $a) => substr(preg_replace('/\D/', '', (string) $a->patient_phone), -10))
            ->filter(fn (string $t) => strlen($t) === 10)
            ->unique()
            ->all();

        $porTelefono = Lead::query()
            ->whereIn('id', $ids)
            ->get(['id', 'phone'])
            ->filter(fn ($lead) => in_array(
                substr(preg_replace('/\D/', '', (string) $lead->phone), -10),
                $telefonosConCita,
                true,
            ))
            ->pluck('id')
            ->all();

        return array_values(array_unique([...$porRelacion, ...$porTelefono]));
    }

    /**
     * Deja el mensaje en el historial del chat, igual que los recordatorios: la
     * doctora lo ve en el CRM y, sobre todo, Lore sabe que ya se envió cuando la
     * paciente conteste — si no, respondería sin entender de qué le hablan.
     */
    private function registrarEnConversacion(Conversation $conversacion): void
    {
        try {
            $conversacion->messages()->create(['role' => 'assistant', 'content' => self::PLANTILLA]);
        } catch (Throwable $e) {
            // El mensaje ya salió; no vale la pena romper la corrida por el historial.
            $this->line('  <fg=yellow>aviso</> no se pudo guardar en el historial: '.$e->getMessage());
        }
    }

    /**
     * @return Collection<int,User>
     */
    private function users(): Collection
    {
        $ref = $this->option('user');

        if (blank($ref)) {
            return User::all();
        }

        return User::query()
            ->when(is_numeric($ref), fn ($q) => $q->whereKey($ref), fn ($q) => $q->where('email', $ref))
            ->get();
    }
}
