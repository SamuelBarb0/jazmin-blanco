<?php

namespace App\Services;

use App\Models\Appointment;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sincroniza citas con Google Calendar usando una cuenta de servicio.
 *
 * No usa la librería google/apiclient (muy pesada): firma directamente un JWT
 * con la llave privada de la cuenta de servicio, lo intercambia por un access
 * token y llama a la REST API de Calendar con el HTTP client de Laravel.
 */
class GoogleCalendarService
{
    private const SCOPE = 'https://www.googleapis.com/auth/calendar';
    private const API = 'https://www.googleapis.com/calendar/v3';

    /**
     * @param  array<string,mixed>|null  $serviceAccount  Credencial JSON decodificada.
     */
    public function __construct(
        private readonly ?array $serviceAccount = null,
        private readonly ?string $calendarId = null,
        private readonly string $timezone = 'America/Bogota',
        private readonly bool $oauth = false,
    ) {
    }

    public static function fromConfig(): self
    {
        // Prioridad: si el usuario conectó su calendario con OAuth ("un clic"),
        // se usa su calendario principal. Si no, la cuenta de servicio.
        if (Settings::hasGoogleOAuth()) {
            return new self(
                // OAuth usa el calendario DEDICADO "Citas Consultorio" del usuario
                // (creado al conectar). Fallback a "primary" solo por seguridad.
                calendarId: Settings::googleOAuthCalendarId() ?: 'primary',
                timezone: Settings::googleTimezone(),
                oauth: true,
            );
        }

        return new self(
            serviceAccount: Settings::googleServiceAccount(),
            calendarId: Settings::googleCalendarId(),
            timezone: Settings::googleTimezone(),
        );
    }

    public function isConfigured(): bool
    {
        if ($this->oauth) {
            return filled($this->calendarId);
        }

        return $this->serviceAccount !== null
            && filled($this->serviceAccount['client_email'] ?? null)
            && filled($this->serviceAccount['private_key'] ?? null)
            && filled($this->calendarId);
    }

    /**
     * Verifica la conexión leyendo la metadata del calendario configurado.
     * Devuelve el nombre (summary) del calendario.
     */
    public function ping(): string
    {
        $this->assertConfigured();

        $response = Http::withToken($this->accessToken())
            ->timeout(30)
            ->get(self::API.'/calendars/'.rawurlencode($this->calendarId));

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response->json(), $response->status()));
        }

        return $response->json('summary', $this->calendarId);
    }

    /**
     * Crea el evento en Google y devuelve su id.
     */
    public function createEvent(Appointment $appointment): string
    {
        $this->assertConfigured();

        $response = Http::withToken($this->accessToken())
            ->timeout(30)
            ->post(self::API.'/calendars/'.rawurlencode($this->calendarId).'/events', $this->eventBody($appointment));

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response->json(), $response->status()));
        }

        return $response->json('id');
    }

    /**
     * Actualiza el evento ya existente en Google.
     */
    public function updateEvent(Appointment $appointment): void
    {
        $this->assertConfigured();

        if (! filled($appointment->google_event_id)) {
            throw new RuntimeException('La cita no tiene un evento de Google asociado.');
        }

        $response = Http::withToken($this->accessToken())
            ->timeout(30)
            ->patch(
                self::API.'/calendars/'.rawurlencode($this->calendarId).'/events/'.rawurlencode($appointment->google_event_id),
                $this->eventBody($appointment),
            );

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response->json(), $response->status()));
        }
    }

    /**
     * Devuelve los bloques ocupados del calendario entre dos instantes (free/busy).
     *
     * @param  string  $timeMin  RFC3339 con offset (p. ej. 2026-06-24T00:00:00-05:00)
     * @param  string  $timeMax  RFC3339 con offset
     * @return array<int,array{start:string,end:string}>
     */
    public function busyTimes(string $timeMin, string $timeMax): array
    {
        $this->assertConfigured();

        $response = Http::withToken($this->accessToken())
            ->timeout(30)
            ->post(self::API.'/freeBusy', [
                'timeMin' => $timeMin,
                'timeMax' => $timeMax,
                'timeZone' => $this->timezone,
                'items' => [['id' => $this->calendarId]],
            ]);

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response->json(), $response->status()));
        }

        $calendars = $response->json('calendars', []);

        return $calendars[$this->calendarId]['busy'] ?? [];
    }

    /**
     * Borra el evento de Google. Ignora el 404/410 (ya no existe).
     */
    public function deleteEvent(string $eventId): void
    {
        $this->assertConfigured();

        $response = Http::withToken($this->accessToken())
            ->timeout(30)
            ->delete(self::API.'/calendars/'.rawurlencode($this->calendarId).'/events/'.rawurlencode($eventId));

        if ($response->failed() && ! in_array($response->status(), [404, 410], true)) {
            throw new RuntimeException($this->errorMessage($response->json(), $response->status()));
        }
    }

    /**
     * Cuerpo del evento de Calendar a partir de la cita.
     *
     * @return array<string,mixed>
     */
    private function eventBody(Appointment $appointment): array
    {
        $appointment->loadMissing('service');

        $serviceName = $appointment->service?->name;
        $summary = $serviceName
            ? "{$serviceName} — {$appointment->patient_name}"
            : "Cita — {$appointment->patient_name}";

        $descriptionLines = array_filter([
            $serviceName ? "Servicio: {$serviceName}" : null,
            $appointment->patient_phone ? "Teléfono: {$appointment->patient_phone}" : null,
            $appointment->patient_email ? "Correo: {$appointment->patient_email}" : null,
            $appointment->notes ? "Notas: {$appointment->notes}" : null,
            'Agendado desde el CRM de la Dra. Jasmin Blanco.',
        ]);

        return [
            'summary' => $summary,
            'description' => implode("\n", $descriptionLines),
            // Hora local "de pared" (sin offset) + timeZone: así Google la ubica
            // en la zona horaria del consultorio sin conversiones inesperadas.
            'start' => [
                'dateTime' => $appointment->starts_at->format('Y-m-d\TH:i:s'),
                'timeZone' => $this->timezone,
            ],
            'end' => [
                'dateTime' => $appointment->ends_at->format('Y-m-d\TH:i:s'),
                'timeZone' => $this->timezone,
            ],
        ];
    }

    /**
     * Obtiene (y cachea ~55 min) un access token vía el flujo JWT de cuenta de servicio.
     */
    private function accessToken(): string
    {
        // Modo OAuth: token del propio usuario (renovado por GoogleOAuthService).
        if ($this->oauth) {
            return app(GoogleOAuthService::class)->accessToken();
        }

        $email = $this->serviceAccount['client_email'];

        return Cache::remember('google_calendar_token_'.md5($email), 3300, function () {
            $now = time();
            $tokenUri = $this->serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token';

            $claims = [
                'iss' => $this->serviceAccount['client_email'],
                'scope' => self::SCOPE,
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ];

            $jwt = $this->signJwt($claims);

            $response = Http::asForm()->timeout(30)->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if ($response->failed()) {
                throw new RuntimeException(
                    'No se pudo autenticar con Google: '.($response->json('error_description') ?? $response->body())
                );
            }

            return $response->json('access_token');
        });
    }

    /**
     * Firma un JWT RS256 con la llave privada de la cuenta de servicio.
     *
     * @param  array<string,mixed>  $claims
     */
    private function signJwt(array $claims): string
    {
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->base64Url(json_encode($claims));
        $signingInput = "{$header}.{$payload}";

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $this->serviceAccount['private_key'], OPENSSL_ALGO_SHA256);

        if (! $ok) {
            throw new RuntimeException('No se pudo firmar el token: la llave privada de la cuenta de servicio no es válida.');
        }

        return "{$signingInput}.".$this->base64Url($signature);
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Google Calendar no está configurado. Agrega la cuenta de servicio y el ID del calendario en Configuración → Google Calendar.');
        }
    }

    /**
     * @param  array<string,mixed>|null  $body
     */
    private function errorMessage(?array $body, int $status): string
    {
        $message = $body['error']['message'] ?? $body['error_description'] ?? 'Error desconocido';

        if ($status === 404) {
            return "Google respondió 404: no se encontró el calendario «{$this->calendarId}». Verifica el ID y que lo hayas compartido con la cuenta de servicio.";
        }

        if ($status === 403) {
            if ($this->oauth) {
                return "Google respondió 403: falta permiso sobre el calendario. Detalle: {$message}";
            }

            return "Google respondió 403: la cuenta de servicio no tiene permiso sobre el calendario. Comparte el calendario con «{$this->serviceAccount['client_email']}» y dale permiso para «Hacer cambios en los eventos». Detalle: {$message}";
        }

        return "Google Calendar respondió {$status}: {$message}";
    }
}
