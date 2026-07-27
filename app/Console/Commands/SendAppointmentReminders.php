<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\User;
use App\Services\WhatsAppService;
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

    protected $description = 'Envía por WhatsApp los recordatorios de cita (2 días antes y 24 horas antes)';

    /** No molestar con un recordatorio si la cita es prácticamente ya. */
    private const MARGEN_MINIMO_HORAS = 2;

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

        foreach ($this->users() as $user) {
            foreach (['2d', '1d'] as $tipo) {
                foreach ($this->pendientes($user, $tipo, $ahora) as $cita) {
                    $telefono = $this->telefono($cita, $config['country_code']);

                    if (! $telefono) {
                        $sinTelefono++;
                        $this->line("  <fg=yellow>sin teléfono</> {$cita->patient_name} · ".$cita->starts_at->format('d/m H:i'));

                        continue;
                    }

                    $texto = $this->mensaje($cita, $ahora, $tz);

                    if ($dry) {
                        $this->line("  <fg=cyan>[{$tipo}]</> {$cita->patient_name} · {$telefono} · ".$cita->starts_at->format('d/m H:i'));
                        $this->line("      {$texto}");
                        $enviados++;

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
                        $fallidos++;
                        $this->line("  <fg=red>falló</> [{$tipo}] {$cita->patient_name} · {$telefono}");

                        continue;
                    }

                    // La marca se pone SIEMPRE que el envío salió bien, para no
                    // repetir el recordatorio en la siguiente corrida.
                    $cita->forceFill([
                        $tipo === '2d' ? 'reminder_2d_sent_at' : 'reminder_1d_sent_at' => now(),
                    ])->save();

                    $this->registrarEnConversacion($cita, $texto);

                    $enviados++;
                    $this->line("  <fg=green>enviado</> [{$tipo}] {$cita->patient_name} · {$telefono} · ".$cita->starts_at->format('d/m H:i'));
                }
            }
        }

        $this->newLine();
        $modo = $config['template'] ? "plantilla «{$config['template']}»" : 'texto libre (solo llega dentro de la ventana de 24h)';
        $this->info(($dry ? 'Simulación · ' : '')."Recordatorios: {$enviados} · fallidos: {$fallidos} · sin teléfono: {$sinTelefono} · modo: {$modo}");

        return self::SUCCESS;
    }

    /**
     * Citas que toca recordar ahora.
     *
     * - 2d: la cita cae entre 24 y 48 horas a futuro.
     * - 1d: la cita cae entre el margen mínimo y 24 horas a futuro.
     *
     * @return Collection<int,Appointment>
     */
    private function pendientes(User $user, string $tipo, Carbon $ahora): Collection
    {
        $columna = $tipo === '2d' ? 'reminder_2d_sent_at' : 'reminder_1d_sent_at';

        [$desde, $hasta] = $tipo === '2d'
            ? [$ahora->copy()->addHours(24), $ahora->copy()->addHours(48)]
            : [$ahora->copy()->addHours(self::MARGEN_MINIMO_HORAS), $ahora->copy()->addHours(24)];

        return $user->appointments()
            ->whereNull($columna)
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->whereBetween('starts_at', [$desde, $hasta])
            ->with(['service:id,name', 'lead:id,name,phone'])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Teléfono del paciente en formato internacional (el que exige WhatsApp).
     * Sale de la cita o, si no, del lead vinculado.
     */
    private function telefono(Appointment $cita, string $codigoPais): ?string
    {
        $bruto = $cita->patient_phone ?: $cita->lead?->phone;
        $d = preg_replace('/\D/', '', (string) $bruto);

        if (strlen($d) < 10) {
            return null;
        }

        // Ya trae indicativo (57…, 1…, 34…): se deja como está.
        if (strlen($d) > 10) {
            return $d;
        }

        return $codigoPais.$d;
    }

    /** "en 2 días" / "mañana" / "hoy", según el tiempo real que falte. */
    private function cuando(Appointment $cita, Carbon $ahora, string $tz): string
    {
        $inicio = $cita->starts_at->copy()->shiftTimezone($tz);
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

        if (blank($ref)) {
            return User::all();
        }

        return User::query()
            ->when(is_numeric($ref), fn ($q) => $q->whereKey($ref), fn ($q) => $q->where('email', $ref))
            ->get();
    }
}
