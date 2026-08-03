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
            // `PAGADO` entra a propósito, siempre que le falte la cita: si el
            // pago llegó y agendar falló, antes el link quedaba huérfano PARA
            // SIEMPRE, porque al pasar a PAID salía de este filtro y nadie
            // volvía a mirarlo. La ventana de gracia acota los reintentos.
            $pendientes = PaymentLink::where('user_id', $user->id)
                ->whereIn('status', ['ACTIVE', 'PENDING', 'PROCESSING', PaymentLink::PAGADO])
                ->whereNull('appointment_id')
                ->where(fn ($q) => $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now()->subHours(self::GRACIA_HORAS)))
                ->orderBy('id')
                ->get();

            foreach ($pendientes as $link) {
                $revisados++;

                // Pagado pero sin cita: el dinero ya entró, lo que falló fue
                // agendar. No hay que volver a preguntarle a la pasarela, solo
                // reintentar la cita por si el hueco se liberó o Google volvió.
                if ($link->status === PaymentLink::PAGADO) {
                    if ($dry) {
                        $this->line("  <fg=cyan>[{$link->reference}]</> pagado sin cita"
                            .($link->canAutoBook() ? ' · reintentaría agendar '.$link->booking['fecha_hora'] : ' · sin horario guardado'));

                        continue;
                    }

                    // En silencio: el primer fallo ya se registró como error, y
                    // repetirlo cada 5 minutos durante horas taparía el log.
                    if ($link->canAutoBook() && $this->agendar($user, $link, silencioso: true)) {
                        $agendadas++;
                    }

                    continue;
                }

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

    /**
     * Crea la cita del link ya pagado y se la confirma a la paciente.
     *
     * `$silencioso` es para los REINTENTOS de un link que ya quedó pagado sin
     * cita: el fallo original ya se registró, así que repetirlo en cada barrido
     * solo llenaría el log. Si el reintento funciona, se avisa igual.
     */
    private function agendar(User $user, PaymentLink $link, bool $silencioso = false): bool
    {
        $bot = BotService::fromUser($user)->forConversation($link->conversation);

        try {
            $resultado = $bot->createBooking($link->booking);
        } catch (Throwable $e) {
            if (! $silencioso) {
                Log::error('No se pudo agendar automáticamente tras el pago', [
                    'payment_link_id' => $link->id,
                    'error' => $e->getMessage(),
                ]);
            }
            $this->error("      error al agendar: {$e->getMessage()}");

            return false;
        }

        if (! $resultado['appointment']) {
            // El horario se ocupó entre que pagó y que lo detectamos. La cita no
            // se pierde: queda el pago registrado y la doctora lo ve.
            //
            // Va como ERROR y no como warning aunque no sea una excepción: en
            // producción `LOG_LEVEL=error` descarta los warning, y este es
            // justo el caso que NO puede pasar desapercibido — hay dinero
            // cobrado sin cita. Pasó de verdad el 2026-08-03 y no dejó rastro.
            if (! $silencioso) {
                Log::error('Pago confirmado pero no se pudo agendar', [
                    'payment_link_id' => $link->id,
                    'motivo' => $resultado['message'],
                ]);
            }
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
            Log::error('No se pudo avisar del pago confirmado', [
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
