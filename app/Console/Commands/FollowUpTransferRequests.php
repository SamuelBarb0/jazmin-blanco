<?php

namespace App\Console\Commands;

use App\Models\TransferRequest;
use App\Services\WhatsAppService;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Seguimiento de las pacientes que dijeron «pago por transferencia» y no han
 * mandado el comprobante.
 *
 * Existe porque desde que la cita no se crea sin comprobante, quedarse callado
 * dejaría a la paciente creyendo que tiene cupo. Se le insiste UNA vez y, si
 * no llega, se le avisa que el horario quedó libre. Ni más: cada mensaje que
 * sale es una conversación facturable con Meta.
 */
class FollowUpTransferRequests extends Command
{
    protected $signature = 'transfers:follow-up {--dry : Muestra a quién se le escribiría, sin enviar nada}';

    protected $description = 'Recuerda el comprobante de transferencia y libera los horarios que nadie pagó';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $config = Settings::transferProofConfig();
        $whatsapp = WhatsAppService::fromConfig();

        if (! $whatsapp->isConfigured() && ! $dry) {
            $this->error('WhatsApp no está configurado.');

            return self::FAILURE;
        }

        $recordados = $this->recordar($whatsapp, $config['reminder_hours'], $dry);
        $liberados = $this->liberar($whatsapp, $dry);

        $this->newLine();
        $this->info("Recordatorios: {$recordados} · horarios liberados: {$liberados}".($dry ? ' (simulación)' : ''));

        return self::SUCCESS;
    }

    /** Un solo recordatorio por solicitud, pasadas las horas configuradas. */
    private function recordar(WhatsAppService $whatsapp, int $horas, bool $dry): int
    {
        $pendientes = TransferRequest::with(['conversation.lead'])
            ->pendientes()
            ->whereNull('reminded_at')
            ->where('created_at', '<=', now()->subHours($horas))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();

        $this->line("Por recordar: {$pendientes->count()}");
        $enviados = 0;

        foreach ($pendientes as $solicitud) {
            $texto = "¡Hola{$this->nombre($solicitud)}! 😊 Sigo pendiente de tu comprobante para poder apartarte "
                .$this->cuando($solicitud).' Cuando hagas la transferencia, envíamelo por aquí y te confirmo la cita. '
                .'Mientras tanto ese horario sigue disponible para otras pacientes, así que mejor pronto 💙';

            if ($this->escribir($whatsapp, $solicitud, $texto, $dry, 'recordatorio')) {
                if (! $dry) {
                    $solicitud->forceFill(['reminded_at' => now()])->save();
                }
                $enviados++;
            }
        }

        return $enviados;
    }

    /** Vencidas: se le avisa que el cupo se liberó y se cierra la solicitud. */
    private function liberar(WhatsAppService $whatsapp, bool $dry): int
    {
        $vencidas = TransferRequest::with(['conversation.lead'])
            ->pendientes()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        $this->line("Por liberar: {$vencidas->count()}");
        $cerradas = 0;

        foreach ($vencidas as $solicitud) {
            $texto = "¡Hola{$this->nombre($solicitud)}! Como no alcanzó a llegarnos el comprobante, "
                .$this->cuando($solicitud, 'el horario de')." quedó disponible para otras pacientes.\n\n"
                .'Si todavía quieres tu valoración, escríbeme por aquí y con gusto buscamos un nuevo espacio 💙';

            // Se cierra SIEMPRE, salga o no el mensaje: si no, la solicitud
            // vencida se quedaría dando vueltas en cada corrida.
            $this->escribir($whatsapp, $solicitud, $texto, $dry, 'liberación');

            if (! $dry) {
                $solicitud->forceFill(['status' => TransferRequest::VENCIDA])->save();
            }

            $cerradas++;
        }

        return $cerradas;
    }

    /**
     * Envía respetando las mismas guardas que el resto: teléfono, lista de
     * pruebas y la ventana de 24 h de WhatsApp —fuera de ella el texto libre
     * ni sale, así que no se gasta la llamada.
     */
    private function escribir(WhatsAppService $whatsapp, TransferRequest $solicitud, string $texto, bool $dry, string $tipo): bool
    {
        $conversacion = $solicitud->conversation;
        $telefono = $conversacion?->lead?->phone;
        $quien = $conversacion?->lead?->name ?: '—';

        if (blank($telefono)) {
            $this->line("  <fg=gray>sin teléfono</> {$quien}");

            return false;
        }

        if (! $conversacion->windowIsOpen()) {
            $this->line("  <fg=gray>ventana de 24 h cerrada</> {$quien} · {$telefono}");

            return false;
        }

        if (! Settings::autoMessagingAllows($telefono)) {
            $this->line("  <fg=gray>fuera de la lista de pruebas</> {$telefono}");

            return false;
        }

        if ($dry) {
            $this->line("  <fg=cyan>[{$tipo}]</> {$quien} · {$telefono}");

            return true;
        }

        try {
            $ok = $whatsapp->forPhone($conversacion->phone_number_id)->sendText($telefono, $texto);
        } catch (Throwable $e) {
            $this->error("  Error con {$telefono}: ".$e->getMessage());

            return false;
        }

        if (! $ok) {
            $this->line("  <fg=red>falló</> {$telefono}");

            return false;
        }

        try {
            $conversacion->messages()->create(['role' => 'assistant', 'content' => $texto]);
        } catch (Throwable $e) {
            $this->line('  <fg=yellow>aviso</> no se pudo guardar en el historial: '.$e->getMessage());
        }

        $this->line("  <fg=green>{$tipo} enviado</> {$quien} · {$telefono}");

        return true;
    }

    private function nombre(TransferRequest $solicitud): string
    {
        $nombre = trim((string) ($solicitud->booking['nombre_paciente'] ?? $solicitud->conversation?->lead?->name ?? ''));

        return $nombre !== '' ? ' '.explode(' ', $nombre)[0] : '';
    }

    /** «tu cita del viernes 4 de septiembre a las 4:00 pm». */
    private function cuando(TransferRequest $solicitud, string $prefijo = 'tu cita del'): string
    {
        $cuando = $solicitud->cuando();

        if (blank($cuando)) {
            return $prefijo === 'tu cita del' ? 'tu cita.' : 'tu horario.';
        }

        $fecha = Carbon::parse($cuando, Settings::googleTimezone())
            ->locale('es')
            ->isoFormat('dddd D [de] MMMM [a las] h:mm a');

        return "{$prefijo} {$fecha}.";
    }
}
