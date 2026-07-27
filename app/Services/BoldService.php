<?php

namespace App\Services;

use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pagos en línea con Bold.
 *
 * Resuelve el problema de fondo: hasta ahora el consultorio tenía UN solo link
 * de monto fijo, igual para todas las pacientes, así que era imposible saber
 * quién había pagado. Aquí se genera un link por paciente con una `reference`
 * propia y se le pregunta a Bold por su estado cuando hace falta.
 *
 * Solo se usa la llave de IDENTIDAD; la secreta es para validar la firma de los
 * webhooks, que hoy no usamos (la confirmación es bajo demanda).
 */
class BoldService
{
    private const BASE = 'https://integrations.api.bold.co/online/link/v1';

    /** Medios que se le ofrecen a la paciente en el checkout. */
    public const MEDIOS = ['CREDIT_CARD', 'PSE', 'BOTON_BANCOLOMBIA', 'NEQUI'];

    public function __construct(private readonly ?string $identityKey)
    {
    }

    public static function fromConfig(): self
    {
        return new self(Settings::boldIdentityKey());
    }

    public function isConfigured(): bool
    {
        return filled($this->identityKey);
    }

    /**
     * Crea un link de pago de monto cerrado.
     *
     * @param  int  $amount  valor total en pesos
     * @param  string  $reference  identificador nuestro (máx 60, alfanumérico, _ y -)
     * @return array{payment_link:string, url:string}
     */
    public function createLink(int $amount, string $reference, string $description, ?Carbon $expiresAt = null): array
    {
        $body = [
            'amount_type' => 'CLOSE',
            'amount' => [
                'currency' => 'COP',
                'total_amount' => $amount,
                'tip_amount' => 0,
            ],
            'reference' => self::sanitizeReference($reference),
            // Bold exige entre 2 y 100 caracteres.
            'description' => Str::limit(trim($description) ?: 'Pago', 99, ''),
            'payment_methods' => self::MEDIOS,
        ];

        if ($expiresAt) {
            // Bold espera NANOsegundos desde época Unix, no segundos.
            $body['expiration_date'] = $expiresAt->getTimestamp() * 1_000_000_000;
        }

        $data = $this->request('post', self::BASE, $body);
        $payload = $data['payload'] ?? [];

        if (blank($payload['url'] ?? null)) {
            throw new RuntimeException('Bold no devolvió el link de pago: '.json_encode($data['errors'] ?? $data));
        }

        return [
            'payment_link' => (string) $payload['payment_link'],
            'url' => (string) $payload['url'],
        ];
    }

    /**
     * Estado actual de un link. Es la vía por la que comprobamos si la paciente
     * pagó de verdad, en vez de creerle.
     *
     * @return array{status:string, payment_method:?string, total:?int, reference:?string}
     */
    public function linkStatus(string $paymentLink): array
    {
        $data = $this->request('get', self::BASE.'/'.urlencode($paymentLink));

        return [
            'status' => (string) ($data['status'] ?? 'UNKNOWN'),
            'payment_method' => $data['payment_method'] ?? null,
            'total' => isset($data['total']) ? (int) $data['total'] : null,
            'reference' => $data['reference'] ?? null,
        ];
    }

    /** Prueba la credencial creando un link corto y desechable. */
    public function ping(): bool
    {
        $this->createLink(
            1000,
            'test-conexion-'.now()->timestamp,
            'Prueba de conexión',
            now()->addMinutes(10),
        );

        return true;
    }

    /**
     * La referencia solo admite alfanuméricos, guion bajo y medio, y máx 60.
     * Se le agrega marca de tiempo para que nunca se repita (Bold la exige única).
     */
    public static function sanitizeReference(string $reference): string
    {
        $limpia = preg_replace('/[^A-Za-z0-9_-]/', '-', $reference);

        return Str::limit($limpia, 60, '');
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $url, array $body = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Bold no está configurado: falta la llave de identidad.');
        }

        $response = Http::withHeaders([
            // Ojo: Bold NO usa Bearer, el prefijo es literalmente "x-api-key".
            'Authorization' => 'x-api-key '.$this->identityKey,
        ])->acceptJson()->timeout(30)->{$method}($url, $body);

        if ($response->failed()) {
            throw new RuntimeException('Bold respondió '.$response->status().': '.$response->body());
        }

        return $response->json() ?? [];
    }
}
