<?php

namespace App\Console\Commands;

use App\Models\WebhookHit;
use Illuminate\Console\Command;

/**
 * «Esta paciente dice que escribió y nadie le contestó.»
 *
 * Este comando responde eso en diez segundos y sin abrir la base. Contesta una
 * de dos cosas, y son muy distintas:
 *
 *   - NO HAY NADA de ese número → Meta nunca nos entregó sus mensajes. El fallo
 *     está fuera de la plataforma y no hay nada que arreglar en el código; con
 *     Cloud API esos mensajes no los ve nadie, tampoco la doctora.
 *   - HAY FILAS → llegó, y la columna del resultado dice qué hicimos con él:
 *     guardado, respondido, o ignorado con su motivo (bot apagado, chat en
 *     pausa, escalado…).
 *
 * Nació del caso del 21-sep-2026, donde distinguir esas dos situaciones costó
 * una tarde de revisar la base a mano.
 */
class InspeccionarEntradasWebhook extends Command
{
    protected $signature = 'whatsapp:entradas
                            {--telefono= : Solo los de este número (vale con o sin el 57)}
                            {--dias=3 : Cuántos días hacia atrás mirar}
                            {--problemas : Solo lo que no acabó guardado ni respondido}';

    protected $description = 'Qué mensajes nos entregó Meta por el webhook y qué hicimos con cada uno';

    public function handle(): int
    {
        $dias = max(1, (int) $this->option('dias'));
        $desde = now()->subDays($dias);

        $q = WebhookHit::where('created_at', '>=', $desde)->latest('id');

        if ($telefono = $this->option('telefono')) {
            // Se busca por el final del número: Meta manda 57XXXXXXXXXX y la
            // doctora suele dictar el número sin indicativo. Exigir el formato
            // exacto convertiría este comando en otra fuente de dudas.
            $cola = mb_substr(preg_replace('/\D+/', '', (string) $telefono), -10);
            $q->where('from_phone', 'like', '%'.$cola);
        }

        if ($this->option('problemas')) {
            $q->whereNotIn('resultado', [WebhookHit::RESULTADO_GUARDADO, WebhookHit::RESULTADO_RESPONDIDO]);
        }

        $filas = $q->limit(200)->get();

        if ($filas->isEmpty()) {
            $this->newLine();
            $this->warn('No hay NINGUNA entrada que encaje en los últimos '.$dias.' día(s).');

            if ($telefono) {
                $this->line('Si la paciente asegura haber escrito en ese plazo, sus mensajes');
                $this->line('NO llegaron a la plataforma: Meta no nos los entregó. No es que');
                $this->line('Lore no quisiera responder — nunca los vio.');
            }

            $this->newLine();

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Cuándo', 'De', 'Tipo', 'Resultado', 'Detalle', 'Chat'],
            $filas->map(fn (WebhookHit $h) => [
                $h->created_at->format('d/m H:i:s'),
                $h->from_phone ?: '—',
                $h->tipo ?: '—',
                $h->resultado,
                mb_strimwidth((string) $h->detalle, 0, 38, '…'),
                $h->conversation_id ? '#'.$h->conversation_id : '—',
            ])->all(),
        );

        $resumen = $filas->countBy('resultado')
            ->map(fn ($n, $r) => "{$r}={$n}")
            ->implode('  ');

        $this->line('  '.$filas->count().' entrada(s) · '.$resumen);
        $this->newLine();

        return self::SUCCESS;
    }
}
