<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Webhook de la WhatsApp Cloud API.
 *
 * - GET  : verificación inicial que pide Meta (devuelve el hub.challenge).
 * - POST : recepción de mensajes entrantes; cada uno se procesa en cola.
 *
 * Vive bajo el grupo de rutas "api" (sin sesión ni CSRF), porque Meta llama
 * desde sus servidores sin cookies ni token.
 */
class WhatsAppWebhookController extends Controller
{
    /**
     * Verificación del webhook (Meta lo llama una sola vez al configurarlo).
     * PHP convierte los puntos de "hub.mode" en guiones bajos: "hub_mode".
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $expected = config('services.whatsapp.verify_token');

        if ($mode === 'subscribe' && filled($expected) && hash_equals((string) $expected, (string) $token)) {
            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Recepción de eventos. Respondemos 200 de inmediato y dejamos el trabajo
     * pesado (llamar a Claude y responder) para la cola.
     */
    public function handle(Request $request): Response
    {
        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) data_get($entry, 'changes', []) as $change) {
                $value = data_get($change, 'value', []);

                $profileName = data_get($value, 'contacts.0.profile.name');

                foreach ((array) data_get($value, 'messages', []) as $message) {
                    $waId = data_get($message, 'id');
                    $from = data_get($message, 'from');

                    if (blank($waId) || blank($from)) {
                        continue;
                    }

                    // Deduplicación: Meta reintenta si tardamos; no procesamos
                    // dos veces el mismo mensaje.
                    if (! Cache::add('wa_msg_'.$waId, true, now()->addMinutes(10))) {
                        continue;
                    }

                    ProcessWhatsAppMessage::dispatch(
                        from: (string) $from,
                        text: $this->extractText($message),
                        profileName: $profileName ? (string) $profileName : null,
                        referral: $this->extractReferral($message),
                    );
                }
            }
        }

        return response('EVENT_RECEIVED', 200);
    }

    /**
     * Datos del anuncio Click-to-WhatsApp del que vino el paciente, si aplica.
     * Meta solo lo incluye en el primer mensaje tras tocar el anuncio.
     *
     * @param  array<string,mixed>  $message
     * @return array<string,mixed>|null
     */
    private function extractReferral(array $message): ?array
    {
        $referral = data_get($message, 'referral');
        if (! is_array($referral) || blank(data_get($referral, 'source_id'))) {
            return null;
        }

        // Nos quedamos solo con lo útil (sin URLs de imagen pesadas).
        return [
            'source_id' => (string) data_get($referral, 'source_id'),
            'source_type' => data_get($referral, 'source_type'),
            'source_url' => data_get($referral, 'source_url'),
            'headline' => data_get($referral, 'headline'),
            'body' => data_get($referral, 'body'),
            'ctwa_clid' => data_get($referral, 'ctwa_clid'),
        ];
    }

    /**
     * Saca el texto del mensaje según su tipo. Devuelve '' para formatos que
     * todavía no entendemos (imagen, audio, etc.).
     *
     * @param  array<string,mixed>  $message
     */
    private function extractText(array $message): string
    {
        return match (data_get($message, 'type')) {
            'text' => (string) data_get($message, 'text.body', ''),
            'button' => (string) data_get($message, 'button.text', ''),
            'interactive' => (string) (
                data_get($message, 'interactive.button_reply.title')
                ?? data_get($message, 'interactive.list_reply.title')
                ?? ''
            ),
            default => '',
        };
    }
}
