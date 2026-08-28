<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\User;
use App\Services\BotService;
use App\Services\WhatsAppService;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reanuda al asistente en los chats que se pausaron solos, y le responde a
 * quien se quedó esperando.
 *
 * El agujero que tapa (visto en producción el 28-ago-2026): la reanudación
 * automática ya existía —`Conversation::debeReanudarAlAsistente()`— pero SOLO se
 * evaluaba dentro de `ProcessWhatsAppMessage`, o sea cuando la paciente volvía a
 * escribir. Quien escribe UNA vez poco después de que alguien le contestara a
 * mano, y no insiste, se queda sin respuesta para siempre: la pausa ya caducó,
 * pero no hay nada que lo mire. Ese día había 7 chats así, dos de ellos con la
 * ventana de 24 h todavía abierta.
 *
 * Corre cada hora. Los umbrales se miden contra "ahora", así que una corrida
 * perdida la recupera la siguiente en vez de saltarse a nadie.
 *
 * REGLA IMPORTANTE: un chat donde la paciente está esperando NO se reanuda si no
 * se le puede contestar en esta misma corrida. Reanudarlo apagaría el aviso
 * «Escribió y nadie respondió» de la bandeja —que es lo único que hoy delata a
 * esas pacientes— y la dejaría igual de muda, pero ya sin marca. Mejor dejarlo
 * en pausa y que la corrida siguiente (o la doctora) lo atienda.
 */
class ResumePausedConversations extends Command
{
    protected $signature = 'conversations:resume-paused
                            {--dry-run : Muestra qué chats se reanudarían y a quién se le respondería, sin tocar nada}
                            {--force : Responde aunque sea fuera de la franja horaria decente}
                            {--limit=10 : Máximo de respuestas del asistente en esta corrida}';

    protected $description = 'Reanuda al asistente en los chats cuya pausa automática ya caducó y responde a quien quedó esperando';

    /**
     * Franja en la que es aceptable que a una paciente le entre un mensaje.
     *
     * La misma que usa `conversations:send-reactivation`. Aquí importa porque el
     * mensaje que se responde lleva por definición horas parado: contestarlo a
     * las 3 de la mañana no lo hace más útil, solo despierta a alguien.
     */
    private const HORA_DESDE = 8;

    private const HORA_HASTA = 20;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // El interruptor general apaga todo lo automático. En simulación no
        // frena: sirve para ver el estado sin escribirle a nadie.
        if (! $dry && ! Settings::whatsappBotEnabled()) {
            $this->warn('El bot de WhatsApp está apagado; no se reanuda ningún chat.');

            return self::SUCCESS;
        }

        $whatsapp = WhatsAppService::fromConfig();
        if (! $dry && ! $whatsapp->isConfigured()) {
            $this->error('WhatsApp no está configurado (faltan WHATSAPP_ACCESS_TOKEN o WHATSAPP_PHONE_ID).');

            return self::FAILURE;
        }

        $tz = Settings::googleTimezone();
        $ahora = Carbon::now($tz);
        $franjaDecente = (bool) $this->option('force')
            || ($ahora->hour >= self::HORA_DESDE && $ahora->hour < self::HORA_HASTA);

        $tope = max(0, (int) $this->option('limit'));
        $codigoPais = Settings::reminderConfig()['country_code'];
        $numerosDePrueba = Settings::whatsappTestNumbers();

        $reanudados = 0;
        $respondidos = 0;
        $aplazados = 0;
        $mudos = 0;
        $fallidos = 0;

        // Una pasada por CLÍNICA, no por login: los datos cuelgan de `cuenta_id`
        // y varios usuarios comparten cuenta, así que iterar `User::all()`
        // procesaría el mismo consultorio una vez por persona.
        foreach (User::unoPorCuenta() as $user) {
            $bot = BotService::fromUser($user);

            $candidatas = $user->conversations()
                ->where('channel', '!=', 'panel')
                ->where('bot_enabled', false)
                ->with('lead:id,name,phone')
                ->get()
                // El filtro de verdad no es SQL: mira la pausa manual, el
                // escalado y las horas de silencio configuradas.
                ->filter(fn (Conversation $c) => $c->debeReanudarAlAsistente());

            foreach ($candidatas as $conversation) {
                $nombre = $conversation->lead?->name ?: "conversación #{$conversation->id}";
                $ultimo = $conversation->messages()->latest('id')->first();

                // Nadie espera: el último que habló fuimos nosotros. Se reanuda
                // y ya está; el próximo mensaje de la paciente lo atiende Lore
                // por el camino normal.
                if ($ultimo?->role !== 'user') {
                    if (! $dry) {
                        $this->reanudar($conversation);
                    }
                    $reanudados++;
                    $this->line("  <fg=green>reanudado</> {$nombre}");

                    continue;
                }

                // Aquí sí hay alguien esperando. Fuera de la ventana de 24 h no
                // hay forma de contestarle texto libre, así que se deja en pausa
                // con su aviso puesto en la bandeja.
                if (! $conversation->windowIsOpen()) {
                    $mudos++;
                    $this->line("  <fg=red>espera y la ventana de 24 h está cerrada</> {$nombre} — solo por llamada");

                    continue;
                }

                if (! $franjaDecente) {
                    $aplazados++;
                    $this->line("  <fg=yellow>aplazado</> {$nombre} — fuera de la franja ({$ahora->format('H:i')} en {$tz})");

                    continue;
                }

                if ($respondidos >= $tope) {
                    $aplazados++;
                    $this->line("  <fg=yellow>aplazado</> {$nombre} — tope de {$tope} respuestas alcanzado");

                    continue;
                }

                $telefono = Settings::phoneWithCountryCode($conversation->lead?->phone, $codigoPais);
                if (! $telefono) {
                    $mudos++;
                    $this->line("  <fg=red>sin teléfono</> {$nombre}");

                    continue;
                }

                // Modo prueba: con la lista blanca cargada, Lore solo le escribe
                // a esos números. Mismo criterio que el webhook.
                if ($numerosDePrueba !== [] && ! Settings::phoneInList($telefono, $numerosDePrueba)) {
                    $mudos++;
                    $this->line("  <fg=gray>fuera de la lista de pruebas</> {$nombre}");

                    continue;
                }

                if ($dry) {
                    $respondidos++;
                    $this->line("  <fg=cyan>se le respondería</> {$nombre} — «".Str::limit((string) $ultimo->content, 60).'»');

                    continue;
                }

                if (! $bot->isReady()) {
                    $this->error('La IA no está configurada (falta ANTHROPIC_API_KEY); no se puede responder.');

                    break 2;
                }

                try {
                    $result = $bot->reply(
                        $conversation,
                        $conversation->campaign_id ? $conversation->campaign : null,
                    );

                    if (trim($result['text']) !== '') {
                        $whatsapp->sendText($telefono, $result['text']);
                    }

                    foreach ($result['media'] as $item) {
                        if (blank($item['url'] ?? null)) {
                            continue;
                        }
                        $whatsapp->sendMedia(
                            $telefono,
                            $item['type'] ?? 'image',
                            $item['url'],
                            $item['caption'] ?? '',
                        );
                    }

                    $conversation->messages()->create([
                        'role' => 'assistant',
                        'sent_by' => 'bot',
                        'content' => $result['text'],
                        'media' => $result['media'] ?: null,
                    ]);

                    // La pausa se levanta DESPUÉS de contestar: si el envío
                    // falla, el chat se queda como estaba y la corrida siguiente
                    // lo vuelve a intentar en vez de darlo por atendido.
                    $this->reanudar($conversation);

                    $reanudados++;
                    $respondidos++;
                    $this->line("  <fg=green>respondido</> {$nombre}");
                } catch (Throwable $e) {
                    $fallidos++;
                    $this->error("  falló al responder a {$nombre}: {$e->getMessage()}");

                    Log::error('No se pudo responder un chat cuya pausa ya había caducado.', [
                        'conversation_id' => $conversation->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->newLine();
        $this->info($dry
            ? sprintf(
                'Simulación: %d %s se reanudarían y %d %s respuesta.',
                $reanudados, $reanudados === 1 ? 'chat' : 'chats',
                $respondidos, $respondidos === 1 ? 'recibiría' : 'recibirían',
            )
            : "Reanudados: {$reanudados} · respondidos: {$respondidos}");

        if ($aplazados) {
            $this->line("Aplazados a la corrida siguiente: {$aplazados}");
        }
        if ($mudos) {
            $this->warn("Esperando sin poder responderles: {$mudos} (ventana de 24 h cerrada, sin teléfono o fuera de la lista de pruebas)");
        }
        if ($fallidos) {
            $this->warn("Fallidos: {$fallidos}");
        }

        return self::SUCCESS;
    }

    private function reanudar(Conversation $conversation): void
    {
        $conversation->forceFill([
            'bot_enabled' => true,
            'bot_paused_at' => null,
        ])->save();

        Log::info('El asistente retoma un chat cuya pausa automática ya había caducado.', [
            'conversation_id' => $conversation->id,
        ]);
    }
}
