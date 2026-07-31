<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\DeliveryFailure;
use App\Models\Message;
use App\Models\PaymentLink;
use App\Models\User;
use App\Services\WhatsAppService;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resumen diario de cómo se está portando el sistema.
 *
 * Nace de un patrón: TODO lo que se rompió en este proyecto se rompió en
 * silencio. El bot estuvo mudo un día entero por un esquema de herramienta mal
 * serializado; los mensajes se aceptaban con HTTP 200 y no se entregaban; el
 * escalamiento se anunciaba y no ocurría. Ninguno daba un error visible: todos
 * *parecían* funcionar, y se descubrieron mirando a mano.
 *
 * Por eso el resumen no cuenta solo actividad, sino AUSENCIAS — mensajes que
 * entraron y nadie respondió, links que nadie comprobó, chats esperando a una
 * persona. Una ausencia es justo lo que un error no reporta.
 */
class SendDailyDigest extends Command
{
    protected $signature = 'resumen:diario
                            {--horas=24 : Ventana a resumir}
                            {--no-enviar : Solo lo muestra, sin mandarlo por WhatsApp}';

    protected $description = 'Resume la actividad y los fallos silenciosos de las últimas horas';

    public function handle(): int
    {
        $horas = max(1, (int) $this->option('horas'));
        $desde = now()->subHours($horas);
        $user = User::query()->first();

        if (! $user) {
            $this->error('No hay usuarios.');

            return self::FAILURE;
        }

        $texto = $this->componer($user, $desde, $horas);

        $this->newLine();
        $this->line($texto);
        $this->newLine();

        // Al log SIEMPRE, en su propio canal: es la copia que sobrevive aunque
        // el envío falle, y no depende del LOG_LEVEL de producción.
        Log::channel('resumen')->info($texto);

        if ($this->option('no-enviar')) {
            return self::SUCCESS;
        }

        $this->enviar($texto);

        return self::SUCCESS;
    }

    private function componer(User $user, Carbon $desde, int $horas): string
    {
        $l = [];
        $l[] = "📋 Resumen del consultorio · últimas {$horas} h";
        $l[] = now()->locale('es')->isoFormat('dddd D [de] MMMM, h:mm a');
        $l[] = '';

        // ── Lo que hay que mirar primero ───────────────────────────────
        $alertas = $this->alertas($user, $desde);

        if ($alertas === []) {
            $l[] = '✅ Sin alertas.';
        } else {
            $l[] = '⚠️ REVISAR:';
            foreach ($alertas as $a) {
                $l[] = '• '.$a;
            }
        }
        $l[] = '';

        // ── Actividad ──────────────────────────────────────────────────
        $entrantes = $this->entrantes($user, $desde)->count();
        $delBot = $this->mensajes($user, $desde)->where('role', 'assistant')->where('sent_by', 'bot')->count();
        $humanos = $this->mensajes($user, $desde)->where('role', 'assistant')->where('sent_by', 'human')->count();
        $chats = $this->entrantes($user, $desde)->distinct('conversation_id')->count('conversation_id');

        $l[] = '💬 Conversaciones';
        $l[] = "   {$entrantes} mensajes recibidos en {$chats} chats";
        $l[] = "   {$delBot} respuestas de Lore · {$humanos} escritas a mano";

        // Los chats que el modo prueba deja sin responder no son una alerta,
        // pero tampoco deben desaparecer del resumen: son pacientes esperando.
        $enEspera = $this->sinAtenderPorDiseno($user, $desde);
        if ($enEspera > 0) {
            $l[] = "   {$enEspera} chat(s) esperando (Lore no les responde por la configuración actual)";
        }

        // ── Pagos y agenda ─────────────────────────────────────────────
        $activos = PaymentLink::where('user_id', $user->id)->whereIn('status', ['ACTIVE', 'PENDING', 'PROCESSING'])->count();
        $pagados = PaymentLink::where('user_id', $user->id)->where('paid_at', '>=', $desde)->count();
        $manana = Appointment::where('user_id', $user->id)
            ->whereBetween('starts_at', [now()->startOfDay()->addDay(), now()->endOfDay()->addDay()])
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->count();

        $l[] = '';
        $l[] = '💳 Pagos y agenda';
        $l[] = "   {$pagados} pagos confirmados · {$activos} links esperando pago";
        $l[] = "   {$manana} citas mañana";

        // ── Estado de los interruptores ────────────────────────────────
        // Van siempre, aunque no haya nada que reportar: un cambio aquí es
        // silencioso y cambia por completo a quién le habla el bot.
        $lista = Settings::whatsappTestNumbers();
        $l[] = '';
        $l[] = '⚙️ Estado';

        if (! Settings::whatsappBotEnabled()) {
            $l[] = '   Lore: EN PAUSA (no responde a nadie)';
        } elseif ($lista !== []) {
            $n = count($lista);
            $l[] = "   Lore: encendida en modo prueba (solo {$n} número".($n === 1 ? '' : 's').')';
        } else {
            $l[] = '   Lore: ENCENDIDA para todas las pacientes';
        }

        return implode("\n", $l);
    }

    /**
     * Las ausencias y los fallos. Es la parte que justifica el resumen.
     *
     * @return list<string>
     */
    private function alertas(User $user, Carbon $desde): array
    {
        $alertas = [];

        // 1. Mensajes que entraron y nadie contestó. Es la señal de que el bot
        //    está mudo — el fallo más caro y el más difícil de notar.
        //
        //    Solo tiene sentido preguntárselo si se ESPERABA una respuesta. Con
        //    el interruptor global apagado no se espera ninguna, y en modo
        //    prueba solo se esperan de los números de la lista blanca: sin este
        //    filtro la alerta salta todos los días por diseño, y una alerta que
        //    grita en falso a diario se acaba ignorando.
        $lista = Settings::whatsappTestNumbers();

        $sinResponder = Settings::whatsappBotEnabled()
            ? $this->entrantes($user, $desde)
                ->get(['conversation_id', 'id'])
                ->groupBy('conversation_id')
                ->filter(function ($msgs, $convId) use ($lista) {
                    $ultimoEntrante = $msgs->max('id');
                    $conv = Conversation::find($convId);

                    // Con el chat en pausa o escalado el silencio es intencional.
                    if (! $conv || ! $conv->bot_enabled) {
                        return false;
                    }

                    // Modo prueba: a los de fuera de la lista no se les responde
                    // a propósito.
                    if ($lista !== [] && ! Settings::phoneInList((string) $conv->lead?->phone, $lista)) {
                        return false;
                    }

                    return ! Message::where('conversation_id', $convId)
                        ->where('id', '>', $ultimoEntrante)
                        ->where('role', 'assistant')
                        ->exists();
                })
            : collect();

        if ($sinResponder->isNotEmpty()) {
            $alertas[] = $sinResponder->count().' chat(s) con mensajes SIN RESPONDER (¿Lore caída?)';
        }

        // 2. Entregas que WhatsApp rechazó, agrupadas por causa.
        $fallos = DeliveryFailure::where('created_at', '>=', $desde)
            ->selectRaw('code, title, count(*) c')
            ->groupBy('code', 'title')
            ->get();

        foreach ($fallos as $f) {
            $alertas[] = "{$f->c} mensaje(s) NO entregados · error {$f->code} ".($f->title ?: '');
        }

        // 3. Pacientes esperando a una persona.
        $esperando = Conversation::where('user_id', $user->id)->whereNotNull('escalated_at')->count();
        if ($esperando > 0) {
            $alertas[] = "{$esperando} chat(s) esperando a una persona";
        }

        // 4. Pagos que entraron pero no se pudieron agendar (se ocupó el hueco).
        $pagadosSinCita = PaymentLink::where('user_id', $user->id)
            ->where('status', PaymentLink::PAGADO)
            ->whereNull('appointment_id')
            ->whereNotNull('booking')
            ->count();
        if ($pagadosSinCita > 0) {
            $alertas[] = "{$pagadosSinCita} pago(s) confirmados SIN cita agendada";
        }

        // 5. Citas que no llegaron a Google Calendar.
        $sinSync = Appointment::where('user_id', $user->id)
            ->where('created_at', '>=', $desde)
            ->whereNotNull('google_sync_error')
            ->count();
        if ($sinSync > 0) {
            $alertas[] = "{$sinSync} cita(s) no se sincronizaron con Google Calendar";
        }

        // 6. Trabajos en cola que murieron.
        $fallidos = DB::table('failed_jobs')->where('failed_at', '>=', $desde)->count();
        if ($fallidos > 0) {
            $alertas[] = "{$fallidos} trabajo(s) en cola fallaron";
        }

        return $alertas;
    }

    /**
     * Chats con mensajes entrantes que NO se respondieron a propósito: el bot
     * global apagado, o el número fuera de la lista de prueba.
     *
     * Se cuentan aparte de las alertas porque no indican avería, pero son
     * pacientes reales esperando y no deberían volverse invisibles.
     */
    private function sinAtenderPorDiseno(User $user, Carbon $desde): int
    {
        $lista = Settings::whatsappTestNumbers();
        $global = Settings::whatsappBotEnabled();

        if ($global && $lista === []) {
            return 0; // Lore responde a todo el mundo: nada es intencional.
        }

        return $this->entrantes($user, $desde)
            ->get(['conversation_id', 'id'])
            ->groupBy('conversation_id')
            ->filter(function ($msgs, $convId) use ($lista, $global) {
                $conv = Conversation::find($convId);

                if (! $conv || ! $conv->bot_enabled) {
                    return false; // pausa manual: ya se ve en otro sitio
                }

                if ($global && Settings::phoneInList((string) $conv->lead?->phone, $lista)) {
                    return false; // sí se le responde
                }

                return ! Message::where('conversation_id', $convId)
                    ->where('id', '>', $msgs->max('id'))
                    ->where('role', 'assistant')
                    ->exists();
            })
            ->count();
    }

    /** Mensajes de las conversaciones reales (no las pruebas del panel). */
    private function mensajes(User $user, Carbon $desde)
    {
        return Message::where('messages.created_at', '>=', $desde)
            ->whereHas('conversation', fn ($q) => $q->where('user_id', $user->id)->where('channel', '!=', 'panel'));
    }

    private function entrantes(User $user, Carbon $desde)
    {
        return $this->mensajes($user, $desde)->where('role', 'user');
    }

    private function enviar(string $texto): void
    {
        $telefono = Settings::get('alerts_phone');

        if (blank($telefono)) {
            $this->warn('No hay número de avisos configurado (ajuste `alerts_phone`); el resumen queda solo en el log.');

            return;
        }

        try {
            $ok = WhatsAppService::fromConfig()->sendText($telefono, $texto);
        } catch (Throwable $e) {
            $ok = false;
            $this->error('No se pudo enviar: '.$e->getMessage());
        }

        // Recordatorio honesto: un `true` aquí solo significa "Meta lo aceptó".
        // Este mensaje lo INICIA el negocio, así que hasta que la cuenta tenga
        // método de pago es probable que no se entregue aunque salga bien.
        $this->line($ok
            ? 'Enviado a '.$telefono.' (aceptado por Meta; la entrega se confirma en el próximo resumen).'
            : 'No salió. El resumen queda en storage/logs/resumen.log.');
    }
}
