<?php

namespace App\Console\Commands;

use App\Models\PaymentLink;
use App\Models\User;
use App\Services\BotService;
use App\Services\MercadoPagoService;
use App\Services\WhatsAppService;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Vigila los links de pago vivos y agenda solo cuando entra el pago.
 *
 * El problema que resuelve: la comprobación del pago era 100% conversacional
 * — el bot solo le preguntaba a la pasarela cuando la paciente escribía "ya
 * pagué". Si pagaba y cerraba WhatsApp, no pasaba NADA: ni cita, ni aviso, y el
 * link se quedaba en ACTIVE para siempre mientras ella esperaba una
 * confirmación que nunca llegaba.
 *
 * Ahora este comando barre los links vivos cada pocos minutos y, en cuanto uno
 * queda pagado, agenda con el horario que se guardó al generarlo y se lo
 * confirma por WhatsApp.
 */
class CheckPendingPayments extends Command
{
    protected $signature = 'payments:check-pending
                            {--dry-run : Solo muestra qué haría, sin agendar ni escribir}
                            {--user= : Limitar a un usuario por id o email}';

    protected $description = 'Comprueba los pagos pendientes y agenda la cita en cuanto se confirman';

    /**
     * Cuánto se sigue vigilando un link después de que vence.
     *
     * La preferencia deja de aceptar pagos al vencer, pero uno iniciado justo
     * antes puede acreditarse más tarde (PSE y Efecty no son inmediatos), así
     * que se le da margen antes de dejarlo por perdido.
     */
    private const GRACIA_HORAS = 6;

    public function handle(): int
    {
        $pasarela = MercadoPagoService::fromConfig();

        if (! $pasarela->isConfigured()) {
            $this->warn('Mercado Pago no está configurado; no hay nada que comprobar.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $agendadas = 0;
        $revisados = 0;
        $vencidos = 0;

        foreach ($this->users() as $user) {
            $pendientes = PaymentLink::where('user_id', $user->id)
                ->whereIn('status', ['ACTIVE', 'PENDING', 'PROCESSING'])
                ->whereNull('appointment_id')
                ->where(fn ($q) => $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now()->subHours(self::GRACIA_HORAS)))
                ->orderBy('id')
                ->get();

            foreach ($pendientes as $link) {
                $revisados++;

                try {
                    $estado = $pasarela->linkStatus($link->reference);
                } catch (Throwable $e) {
                    // Un fallo de red no puede tumbar la corrida entera: el
                    // siguiente barrido lo reintenta.
                    $this->error("  {$link->reference}: {$e->getMessage()}");

                    continue;
                }

                $vencido = $estado['status'] === 'PENDING'
                    && $link->expires_at
                    && $link->expires_at->isPast();

                $final = $vencido ? 'EXPIRED' : $estado['status'];

                if ($dry) {
                    $this->line("  <fg=cyan>[{$link->reference}]</> {$link->status} -> {$final}"
                        .($final === 'PAID' && $link->canAutoBook() ? ' · agendaría '.$link->booking['fecha_hora'] : ''));

                    if ($final === 'EXPIRED') {
                        $vencidos++;
                    }
                    if ($final === 'PAID') {
                        $agendadas++;
                    }

                    continue;
                }

                $link->forceFill([
                    'status' => $final,
                    'payment_method' => $estado['payment_method'] ?? $link->payment_method,
                    'paid_at' => $final === PaymentLink::PAGADO ? ($link->paid_at ?? now()) : null,
                    'checked_at' => now(),
                ])->save();

                if ($final === 'EXPIRED') {
                    $vencidos++;
                    $this->line("  <fg=gray>vencido</> {$link->reference}");

                    continue;
                }

                if ($final !== PaymentLink::PAGADO) {
                    continue;
                }

                $this->line("  <fg=green>pagado</> {$link->reference} · {$estado['payment_method']}");

                if (! $link->canAutoBook()) {
                    // Pagó, pero no hay horario guardado: no se inventa uno. La
                    // doctora lo ve en la bandeja y el bot lo resuelve si la
                    // paciente escribe.
                    $this->line('      sin día y hora guardados: no se agenda sola');

                    continue;
                }

                if ($this->agendar($user, $link)) {
                    $agendadas++;
                }
            }
        }

        $this->newLine();
        $this->info(($dry ? 'Simulación · ' : '')."Links revisados: {$revisados} · agendadas: {$agendadas} · vencidos: {$vencidos}");

        return self::SUCCESS;
    }

    /** Crea la cita del link ya pagado y se la confirma a la paciente. */
    private function agendar(User $user, PaymentLink $link): bool
    {
        $bot = BotService::fromUser($user)->forConversation($link->conversation);

        try {
            $resultado = $bot->createBooking($link->booking);
        } catch (Throwable $e) {
            Log::error('No se pudo agendar automáticamente tras el pago', [
                'payment_link_id' => $link->id,
                'error' => $e->getMessage(),
            ]);
            $this->error("      error al agendar: {$e->getMessage()}");

            return false;
        }

        if (! $resultado['appointment']) {
            // El horario se ocupó entre que pagó y que lo detectamos. La cita no
            // se pierde: queda el pago registrado y la doctora lo ve.
            Log::warning('Pago confirmado pero no se pudo agendar', [
                'payment_link_id' => $link->id,
                'motivo' => $resultado['message'],
            ]);
            $this->line('      <fg=yellow>no se pudo agendar</>: '.$resultado['message']);

            return false;
        }

        $cita = $resultado['appointment'];
        $link->forceFill(['appointment_id' => $cita->id])->save();

        $this->line('      <fg=green>agendada</> '.$cita->starts_at->format('d/m H:i'));

        $this->avisar($link, $cita->starts_at->locale('es')->isoFormat('dddd D [de] MMMM [a las] h:mm a'));

        return true;
    }

    /**
     * Le confirma la cita por WhatsApp.
     *
     * Puede fallar legítimamente: si la paciente pagó y no escribió en 24 horas,
     * la ventana está cerrada y WhatsApp solo entrega plantillas. La cita ya
     * quedó creada, así que el fallo se registra y no rompe nada.
     */
    private function avisar(PaymentLink $link, string $cuando): void
    {
        $telefono = $link->booking['telefono'] ?? $link->lead?->phone;

        if (blank($telefono)) {
            return;
        }

        $texto = "¡Recibimos tu pago! 🎉 Tu cita quedó confirmada para el {$cuando}. "
            .'Te esperamos en '.Settings::botConfig()['clinic_address'].'. '
            .'Si necesitas reprogramarla, respóndenos por este chat.';

        try {
            $enviado = WhatsAppService::fromConfig()->sendText($telefono, $texto);
        } catch (Throwable $e) {
            $enviado = false;
            Log::warning('No se pudo avisar del pago confirmado', [
                'payment_link_id' => $link->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($enviado && $link->conversation) {
            $link->conversation->messages()->create([
                'role' => 'assistant',
                'sent_by' => 'bot',
                'content' => $texto,
            ]);
        }
    }

    /** @return iterable<int,User> */
    private function users(): iterable
    {
        $ref = $this->option('user');

        if (blank($ref)) {
            return User::query()->cursor();
        }

        return User::where('id', $ref)->orWhere('email', $ref)->get();
    }
}
