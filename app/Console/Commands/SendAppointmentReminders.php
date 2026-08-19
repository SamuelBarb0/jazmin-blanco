<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\ReminderOptOut;
use App\Models\User;
use App\Services\WhatsAppService;
use App\Support\PatientLeads;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Recordatorios de cita por WhatsApp: uno dos días antes y otro el día antes.
 *
 * Pensado para correr cada hora desde el scheduler. Las ventanas se calculan
 * contra la hora actual (no contra "la hora exacta del recordatorio"), así que
 * si una corrida se salta, la siguiente lo recupera. Cada cita recibe como
 * máximo un recordatorio de cada tipo: la marca queda en la propia cita.
 */
class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders
                            {--dry-run : Muestra a quién se le enviaría, sin mandar nada}
                            {--force : Envía aunque sea fuera de la franja horaria decente}
                            {--user= : Id o correo de la doctora (por defecto, todas)}';

    protected $description = 'Envía por WhatsApp los recordatorios de cita (24 horas antes y 2 horas antes)';

    /**
     * Frontera entre los dos avisos, en horas.
     *
     * Debajo de ella toca el recordatorio del mismo día; por encima, y hasta las
     * 24 h, el de la víspera. Es el mismo número para los dos porque son tramos
     * contiguos: así ninguna cita se queda entre medias sin ningún aviso.
     */
    private const HORAS_AVISO_CORTO = 2;

    /** Franja en la que es aceptable escribirle a una paciente (hora del consultorio). */
    private const HORA_DESDE = 7;

    private const HORA_HASTA = 20;

    /**
     * Cuerpo del recordatorio. Debe coincidir EXACTAMENTE con la plantilla
     * aprobada en el WhatsApp Manager, cambiando %s por {{1}}, {{2}}, {{3}} y
     * {{4}} en ese mismo orden:
     *
     *   Hola {{1}} 👋 Te recordamos tu cita {{2}} ({{3}}) en {{4}}.
     *   Si necesitas reprogramarla, respóndenos por este chat.
     *
     * La fecha va entre paréntesis a propósito: en español termina en "p. m.",
     * y si cerrara la frase quedaría un doble punto.
     */
    private const PLANTILLA = 'Hola %s 👋 Te recordamos tu cita %s (%s) en %s. Si necesitas reprogramarla, respóndenos por este chat.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $config = Settings::reminderConfig();

        if (! $config['enabled']) {
            $this->warn('Los recordatorios están desactivados en la configuración.');

            return self::SUCCESS;
        }

        // El interruptor general apaga TODO lo automático, no solo las
        // respuestas de Lore. Antes no se consultaba aquí: con el bot apagado
        // los recordatorios seguían saliendo, que es justo lo que el «botón de
        // pánico» debía impedir. En simulación no se frena: sirve para ver qué
        // se enviaría sin enviar nada.
        if (! $dry && ! Settings::whatsappBotEnabled()) {
            $this->warn('El bot de WhatsApp está apagado; no se envía ningún recordatorio.');

            return self::SUCCESS;
        }

        $whatsapp = WhatsAppService::fromConfig();
        if (! $whatsapp->isConfigured() && ! $dry) {
            $this->error('WhatsApp no está configurado (faltan WHATSAPP_ACCESS_TOKEN o WHATSAPP_PHONE_ID).');

            return self::FAILURE;
        }

        $tz = Settings::googleTimezone();
        $ahora = Carbon::now($tz);

        // Nada de mensajes de madrugada: la corrida de la siguiente hora los
        // recupera igual, porque las ventanas se calculan contra "ahora".
        if (! $dry && ! $this->option('force') && ($ahora->hour < self::HORA_DESDE || $ahora->hour >= self::HORA_HASTA)) {
            $this->line("Fuera de la franja de envío ({$ahora->format('H:i')} en {$tz}). No se envía nada.");

            return self::SUCCESS;
        }

        $enviados = 0;
        $fallidos = 0;
        $sinTelefono = 0;
        $excluidos = 0;

        foreach ($this->users() as $user) {
            // El de 24 h primero: si una cita cayera en los dos tramos (recién
            // agendada a menos de 2 h, por ejemplo), manda el más informativo.
            foreach (['24h', '2h'] as $tipo) {
                foreach ($this->pendientes($user, $tipo, $ahora) as $cita) {
                    $telefono = $this->telefono($cita, $config['country_code']);

                    if (! $telefono) {
                        $sinTelefono++;
                        $this->line("  <fg=yellow>sin teléfono</> {$cita->patient_name} · ".$cita->starts_at->format('d/m H:i'));

                        continue;
                    }

                    // La paciente pidió que no le escribiéramos. Se comprueba
                    // AQUÍ, después de resolver el teléfono, porque el opt-out
                    // se guarda por número y el número puede venir de la cita o
                    // del lead. Va antes de cualquier envío o marca.
                    if (ReminderOptOut::has($user->id, $telefono)) {
                        $excluidos++;
                        $this->line("  <fg=gray>sin recordatorios</> {$cita->patient_name} · {$telefono}");

                        continue;
                    }

                    // Modo prueba: con la lista blanca cargada, solo esos
                    // números reciben. Sin esto, encender el canal para probarlo
                    // le mandaba recordatorios a todas las pacientes reales.
                    if (! $dry && ! Settings::autoMessagingAllows($telefono)) {
                        $excluidos++;
                        $this->line("  <fg=gray>fuera de la lista de pruebas</> {$cita->patient_name} · {$telefono}");

                        continue;
                    }

                    $texto = $this->mensaje($cita, $ahora, $tz);

                    if ($dry) {
                        $this->line("  <fg=cyan>[{$tipo}]</> {$cita->patient_name} · {$telefono} · ".$cita->starts_at->format('d/m H:i'));
                        $this->line("      {$texto}");
                        $enviados++;

                        continue;
                    }

                    // Reserva el envío ANTES de mandarlo. El UPDATE condicional solo
                    // lo gana un proceso: si dos corridas coinciden en el mismo
                    // minuto, la segunda ve 0 filas afectadas y se aparta. Marcar
                    // DESPUÉS de enviar dejaba una ventana en la que ambas leían la
                    // cita como pendiente y la paciente recibía el recordatorio dos
                    // veces (pasó 5 veces entre el 10 y el 19 de agosto de 2026).
                    $columna = $tipo === '2h' ? 'reminder_2h_sent_at' : 'reminder_24h_sent_at';

                    $reservada = Appointment::query()
                        ->whereKey($cita->id)
                        ->whereNull($columna)
                        ->update([$columna => now()]);

                    if ($reservada === 0) {
                        $this->line("  <fg=gray>ya enviado por otra corrida</> [{$tipo}] {$cita->patient_name}");

                        continue;
                    }

                    try {
                        $ok = $config['template']
                            ? $whatsapp->sendTemplate($telefono, $config['template'], $config['language'], $this->parametros($cita, $ahora, $tz))
                            : $whatsapp->sendText($telefono, $texto);
                    } catch (Throwable $e) {
                        $ok = false;
                        $this->error("  Error con {$cita->patient_name}: ".$e->getMessage());
                    }

                    if (! $ok) {
                        // Suelta la reserva: el envío no salió, así que la siguiente
                        // corrida debe poder reintentarlo. Sin esto, un fallo de
                        // WhatsApp dejaría a la paciente sin recordatorio para siempre.
                        Appointment::query()->whereKey($cita->id)->update([$columna => null]);

                        $fallidos++;
                        $this->line("  <fg=red>falló</> [{$tipo}] {$cita->patient_name} · {$telefono}");

                        continue;
                    }

                    // La reserva de arriba ya dejó puesta la marca; aquí solo se
                    // refresca el modelo en memoria para lo que venga después.
                    $cita->setAttribute($columna, now());

                    $this->registrarEnConversacion($cita, $texto);

                    $enviados++;
                    $this->line("  <fg=green>enviado</> [{$tipo}] {$cita->patient_name} · {$telefono} · ".$cita->starts_at->format('d/m H:i'));
                }
            }
        }

        $this->newLine();
        $modo = $config['template'] ? "plantilla «{$config['template']}»" : 'texto libre (solo llega dentro de la ventana de 24h)';
        $this->info(($dry ? 'Simulación · ' : '')."Recordatorios: {$enviados} · fallidos: {$fallidos} · sin teléfono: {$sinTelefono} · sin recordatorios: {$excluidos} · modo: {$modo}");

        return self::SUCCESS;
    }

    /**
     * Citas que toca recordar ahora.
     *
     * - 24h: la cita cae entre 2 y 24 horas a futuro.
     * - 2h: la cita cae dentro de las próximas 2 horas.
     *
     * Cada tramo dispara en la PRIMERA corrida en que la cita entra en su
     * ventana, no en un instante exacto: como el comando corre cada hora, el
     * aviso de 2 h llega entre 1 y 2 horas antes, y el de 24 h en cuanto quedan
     * menos de 24. Que la ventana sea un rango y no un punto es lo que hace que
     * una corrida perdida (o la franja nocturna) la recupere la siguiente en
     * vez de saltarse el aviso para siempre.
     *
     * `$desde` = ahora en el tramo corto: una cita que ya empezó no se recuerda.
     *
     * @return Collection<int,Appointment>
     */
    private function pendientes(User $user, string $tipo, Carbon $ahora): Collection
    {
        $columna = $tipo === '2h' ? 'reminder_2h_sent_at' : 'reminder_24h_sent_at';

        [$desde, $hasta] = $tipo === '2h'
            ? [$ahora->copy(), $ahora->copy()->addHours(self::HORAS_AVISO_CORTO)]
            : [$ahora->copy()->addHours(self::HORAS_AVISO_CORTO), $ahora->copy()->addHours(24)];

        return $user->appointments()
            ->whereNull($columna)
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->whereBetween('starts_at', [$desde, $hasta])
            ->with(['service:id,name', 'lead:id,name,phone'])
            ->orderBy('starts_at')
            ->get()
            // Los marcadores del calendario de la doctora ("FESTIVO", "PERU"…)
            // son citas en la agenda, pero no hay nadie a quien recordarle nada.
            ->reject(fn (Appointment $a) => blank($a->patient_phone) && PatientLeads::isNonPatient($a->patient_name))
            ->values();
    }

    /**
     * Teléfono del paciente en formato internacional (el que exige WhatsApp).
     * Sale de la cita o, si no, del lead vinculado.
     */
    private function telefono(Appointment $cita, string $codigoPais): ?string
    {
        // La lógica vive en el modelo desde que se descubrió que el aviso al
        // agendar tenía su propia copia SIN normalizar: a la misma paciente le
        // llegaba el recordatorio y nunca la confirmación.
        return $cita->telefonoWhatsapp($codigoPais);
    }

    /**
     * "en 2 horas" / "mañana" / "hoy", según el tiempo real que falte.
     *
     * Cuando quedan pocas horas se dice en HORAS y no "hoy": es el aviso de
     * última hora, y su gracia está justo en que la paciente sepa cuánto le
     * queda para salir de casa. "Hoy" no le dice eso.
     */
    private function cuando(Appointment $cita, Carbon $ahora, string $tz): string
    {
        $inicio = $cita->starts_at->copy()->shiftTimezone($tz);
        $horas = $ahora->diffInHours($inicio, false);

        if ($horas >= 0 && $horas < self::HORAS_AVISO_CORTO + 1) {
            // Se redondea hacia arriba: entre 60 y 120 minutos, "en 2 horas".
            $restantes = (int) ceil(max($ahora->diffInMinutes($inicio, false), 0) / 60);

            return match (true) {
                $restantes <= 0 => 'en unos minutos',
                $restantes === 1 => 'en 1 hora',
                default => "en {$restantes} horas",
            };
        }

        // Carbon 3 devuelve float: se redondea a días de calendario completos.
        $dias = (int) round($ahora->copy()->startOfDay()->diffInDays($inicio->copy()->startOfDay(), false));

        return match (true) {
            $dias <= 0 => 'hoy',
            $dias === 1 => 'mañana',
            default => "en {$dias} días",
        };
    }

    /** Fecha larga en español: "viernes 29 de agosto a las 10:00 a. m.". */
    private function fecha(Appointment $cita, string $tz): string
    {
        return $cita->starts_at->copy()->shiftTimezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM [a las] h:mm a');
    }

    /**
     * Parámetros de la plantilla aprobada.
     *
     * El ORDEN importa: Meta exige que {{1}}, {{2}}… aparezcan en el cuerpo en
     * ese mismo orden, así que esta lista sigue el texto de PLANTILLA.
     *
     * @return list<string>
     */
    private function parametros(Appointment $cita, Carbon $ahora, string $tz): array
    {
        return [
            $this->primerNombre($cita),                 // {{1}} nombre
            $this->cuando($cita, $ahora, $tz),          // {{2}} "mañana" / "en 2 días"
            $this->fecha($cita, $tz),                   // {{3}} fecha y hora larga
            Settings::botConfig()['clinic_name'],       // {{4}} clínica
        ];
    }

    /**
     * Cuerpo del recordatorio, palabra por palabra igual que la plantilla — así
     * lo que se guarda en el historial del chat es exactamente lo que le llegó
     * a la paciente, se haya enviado por plantilla o como texto libre.
     */
    private function mensaje(Appointment $cita, Carbon $ahora, string $tz): string
    {
        return vsprintf(self::PLANTILLA, $this->parametros($cita, $ahora, $tz));
    }

    private function primerNombre(Appointment $cita): string
    {
        $nombre = trim((string) ($cita->lead?->name ?: $cita->patient_name));
        $primero = explode(' ', $nombre)[0] ?? '';

        return $primero !== '' ? mb_convert_case($primero, MB_CASE_TITLE, 'UTF-8') : 'hola';
    }

    /**
     * Deja el recordatorio en el historial del chat: la doctora lo ve en el CRM
     * y el bot sabe que ya se envió cuando el paciente responda.
     */
    private function registrarEnConversacion(Appointment $cita, string $texto): void
    {
        if (! $cita->lead_id) {
            return;
        }

        try {
            $conversacion = Conversation::firstOrCreate(
                ['user_id' => $cita->user_id, 'lead_id' => $cita->lead_id, 'channel' => 'whatsapp'],
                ['title' => 'WhatsApp · '.($cita->lead?->name ?: $cita->patient_name)],
            );

            $conversacion->messages()->create(['role' => 'assistant', 'content' => $texto]);
        } catch (Throwable $e) {
            // El recordatorio ya salió; no vale la pena romper la corrida por el historial.
            $this->line('  <fg=yellow>aviso</> no se pudo guardar en el historial: '.$e->getMessage());
        }
    }

    /**
     * @return Collection<int,User>
     */
    private function users(): Collection
    {
        $ref = $this->option('user');

        // Uno por cuenta, no todos los logins: ver `User::unoPorCuenta()`.
        if (blank($ref)) {
            return User::unoPorCuenta();
        }

        return User::query()
            ->when(is_numeric($ref), fn ($q) => $q->whereKey($ref), fn ($q) => $q->where('email', $ref))
            ->get();
    }
}
