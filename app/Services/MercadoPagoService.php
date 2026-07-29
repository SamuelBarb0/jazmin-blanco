<?php

namespace App\Services;

use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pagos en línea con Mercado Pago (Checkout Pro).
 *
 * Resuelve el problema de fondo: hasta ahora el consultorio tenía UN solo link
 * de monto fijo, igual para todas las pacientes, así que era imposible saber
 * quién había pagado. Aquí se crea una preferencia por paciente con una
 * `external_reference` propia y después se le pregunta a Mercado Pago si esa
 * referencia tiene un pago aprobado.
 *
 * Solo se usa el access token; la public key es para el checkout embebido en el
 * navegador, que aquí no se usa (la paciente abre el link desde WhatsApp).
 */
class MercadoPagoService
{
    private const BASE = 'https://api.mercadopago.com';

    /** Medios que Mercado Pago ofrece en Colombia. Es informativo, para la pantalla de ajustes. */
    public const MEDIOS = ['credit_card', 'debit_card', 'bank_transfer', 'ticket'];

    public function __construct(private readonly ?string $accessToken)
    {
    }

    public static function fromConfig(): self
    {
        return new self(Settings::mpAccessToken());
    }

    public function isConfigured(): bool
    {
        return filled($this->accessToken);
    }

    /**
     * Crea un link de pago de monto cerrado.
     *
     * @param  int  $amount  valor total en pesos
     * @param  string  $reference  identificador nuestro, con el que después se consulta el pago
     * @return array{payment_link:string, url:string}
     */
    public function createLink(int $amount, string $reference, string $description, ?Carbon $expiresAt = null): array
    {
        $body = [
            'items' => [[
                'title' => Str::limit(trim($description) ?: 'Pago', 250, ''),
                'quantity' => 1,
                'unit_price' => $amount,
                'currency_id' => 'COP',
            ]],
            'external_reference' => self::sanitizeReference($reference),
            // Aparece en el extracto de la tarjeta de la paciente.
            'statement_descriptor' => Str::limit(Settings::botConfig()['clinic_name'], 22, ''),
        ];

        if ($expiresAt) {
            $body['expires'] = true;
            // Mercado Pago espera ISO 8601 con milisegundos y desfase horario.
            $body['expiration_date_to'] = $expiresAt->format('Y-m-d\TH:i:s.vP');
        }

        $data = $this->request('post', self::BASE.'/checkout/preferences', $body);

        if (blank($data['init_point'] ?? null)) {
            throw new RuntimeException('Mercado Pago no devolvió el link de pago: '.json_encode($data));
        }

        return [
            'payment_link' => (string) $data['id'],
            'url' => (string) $data['init_point'],
        ];
    }

    /**
     * Estado del pago asociado a una referencia. Es la vía por la que comprobamos
     * si la paciente pagó de verdad, en vez de creerle.
     *
     * Se consulta por `external_reference` y no por la preferencia, porque una
     * misma preferencia puede acumular varios intentos y lo que interesa es si
     * alguno quedó aprobado.
     *
     * @return array{status:string, payment_method:?string, total:?int, reference:?string}
     */
    public function linkStatus(string $reference): array
    {
        $data = $this->request('get', self::BASE.'/v1/payments/search', [
            'external_reference' => self::sanitizeReference($reference),
            'sort' => 'date_created',
            'criteria' => 'desc',
        ]);

        $pagos = $data['results'] ?? [];

        if ($pagos === []) {
            return ['status' => 'PENDING', 'payment_method' => null, 'total' => null, 'reference' => $reference];
        }

        // Si alguno quedó aprobado, ese manda; si no, el más reciente.
        $pago = collect($pagos)->firstWhere('status', 'approved') ?? $pagos[0];

        return [
            'status' => self::traducirEstado((string) ($pago['status'] ?? '')),
            'payment_method' => $pago['payment_method_id'] ?? null,
            'total' => isset($pago['transaction_amount']) ? (int) $pago['transaction_amount'] : null,
            'reference' => $pago['external_reference'] ?? $reference,
        ];
    }

    /** Comprueba la credencial pidiendo los datos de la cuenta. No mueve dinero ni crea nada. */
    public function ping(): array
    {
        $cuenta = $this->request('get', self::BASE.'/users/me');

        return [
            'nickname' => $cuenta['nickname'] ?? null,
            'email' => $cuenta['email'] ?? null,
            'site_id' => $cuenta['site_id'] ?? null,
        ];
    }

    /**
     * Traduce los estados de Mercado Pago al vocabulario interno, que es el que
     * entiende el bot y el que quedó guardado en `payment_links`.
     */
    public static function traducirEstado(string $estado): string
    {
        return match ($estado) {
            'approved' => 'PAID',
            'authorized', 'in_process', 'in_mediation', 'pending' => 'PROCESSING',
            'rejected' => 'REJECTED',
            'cancelled' => 'CANCELLED',
            'refunded', 'charged_back' => 'REFUNDED',
            default => 'PENDING',
        };
    }

    /** La referencia viaja en la URL de búsqueda: se deja alfanumérica y corta. */
    public static function sanitizeReference(string $reference): string
    {
        return Str::limit(preg_replace('/[^A-Za-z0-9_-]/', '-', $reference), 60, '');
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $url, array $body = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Mercado Pago no está configurado: falta el access token.');
        }

        $response = Http::withToken($this->accessToken)
            ->acceptJson()
            ->timeout(30)
            ->{$method}($url, $body);

        if ($response->failed()) {
            $json = $response->json();
            $detalle = $json['message'] ?? $json['error'] ?? $response->body();

            throw new RuntimeException('Mercado Pago respondió '.$response->status().': '.$detalle);
        }

        return $response->json() ?? [];
    }
}
