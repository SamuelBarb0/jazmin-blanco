<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\DeliveryFailure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

                // A QUÉ línea nuestra llegó esto. Meta siempre lo manda y hasta
                // ahora se tiraba: por eso, con dos cuentas suscritas al mismo
                // webhook, no había forma de saber de cuál venía cada mensaje ni
                // por cuál responder.
                $phoneNumberId = data_get($value, 'metadata.phone_number_id');

                // Acuses de entrega. Meta acepta el envío con un 200 y solo
                // DESPUÉS avisa por aquí si el mensaje se entregó o se cayó;
                // sin esto, un fallo de entrega es invisible y parece que todo
                // salió bien.
                foreach ((array) data_get($value, 'statuses', []) as $status) {
                    $estado = (string) data_get($status, 'status');
                    $datos = [
                        'estado' => $estado,
                        'para' => data_get($status, 'recipient_id'),
                        'mensaje' => data_get($status, 'id'),
                    ];

                    if ($estado === 'failed') {
                        $error = (array) data_get($status, 'errors.0', []);

                        Log::error('WhatsApp NO entregó un mensaje.', $datos + [
                            'errores' => data_get($status, 'errors'),
                        ]);

                        // Además del log, a la base: el log en producción va en
                        // nivel `error` y no lo lee nadie a diario, así que un
                        // fallo así se queda invisible. Guardado, sale en el
                        // resumen diario.
                        DeliveryFailure::create([
                            'phone' => (string) data_get($status, 'recipient_id'),
                            'code' => data_get($error, 'code'),
                            'title' => data_get($error, 'title'),
                            'details' => data_get($error, 'error_data.details') ?: data_get($error, 'message'),
                            'wamid' => (string) data_get($status, 'id'),
                            'created_at' => now(),
                        ]);
                    } else {
                        Log::info('Acuse de WhatsApp.', $datos);
                    }
                }

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
                        media: $this->extractMedia($message),
                        phoneNumberId: $phoneNumberId ? (string) $phoneNumberId : null,
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
            // Imágenes/archivos: el paciente suele mandar la CAPTURA del
            // comprobante de pago. El bot no ve el contenido, pero sí que llegó
            // (para tratarlo como comprobante si están coordinando una cita).
            // El archivo se descarga y se guarda aparte, para que la doctora sí
            // pueda verlo en la bandeja.
            'image' => $this->mediaNote('una imagen', data_get($message, 'image.caption')),
            'document' => $this->mediaNote('un archivo', data_get($message, 'document.caption')),
            // Voz y video: el bot no puede oírlos ni verlos, así que se le dice
            // explícitamente para que pida el dato por escrito en vez de
            // responder cualquier cosa. La doctora sí los tendrá en la bandeja.
            'audio' => '[La paciente envió una nota de voz. No puedes escucharla: pídele con amabilidad que te lo escriba, y avísale que la doctora también la va a escuchar.]',
            'video' => $this->videoNote(data_get($message, 'video.caption')),
            default => '',
        };
    }

    /**
     * Archivo adjunto del mensaje, si trae uno. Solo el descriptor: la descarga
     * ocurre en el job, porque el webhook debe responderle a Meta rápido.
     *
     * @param  array<string,mixed>  $message
     * @return array{kind:string,id:string,mime:string,caption:string,filename:string}|null
     */
    private function extractMedia(array $message): ?array
    {
        $tipo = (string) data_get($message, 'type');

        if (! in_array($tipo, ['image', 'video', 'audio', 'document'], true)) {
            return null;
        }

        $id = data_get($message, "{$tipo}.id");
        if (blank($id)) {
            return null;
        }

        return [
            'kind' => $tipo,
            'id' => (string) $id,
            'mime' => (string) data_get($message, "{$tipo}.mime_type", ''),
            'caption' => (string) data_get($message, "{$tipo}.caption", ''),
            'filename' => (string) data_get($message, "{$tipo}.filename", ''),
        ];
    }

    private function videoNote(?string $caption): string
    {
        $note = '[La paciente envió un video. No puedes verlo: pregúntale de qué se trata, y avísale que la doctora sí lo va a revisar.]';
        $caption = trim((string) $caption);

        return $caption !== '' ? $note.' Lo acompaña este texto: '.$caption : $note;
    }

    /**
     * Nota que se le pasa al bot cuando el paciente envía una imagen o archivo,
     * típicamente el comprobante de pago de la valoración.
     */
    private function mediaNote(string $tipo, ?string $caption): string
    {
        $note = "[El paciente envió {$tipo} por WhatsApp (posible comprobante de pago de la valoración).]";
        $caption = trim((string) $caption);

        return $caption !== '' ? $note.' La acompaña este texto: '.$caption : $note;
    }
}
