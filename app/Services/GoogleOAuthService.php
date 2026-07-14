<?php

namespace App\Services;

use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Conexión "un clic" del calendario del propio usuario vía OAuth 2.0 de Google.
 *
 * A diferencia de la cuenta de servicio (que requiere compartir un calendario a
 * mano), aquí la doctora inicia sesión con su Google, autoriza el acceso a su
 * calendario y listo: usamos su calendario principal. Guardamos el refresh_token
 * cifrado y renovamos el access token cuando expira.
 */
class GoogleOAuthService
{
    private const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN = 'https://oauth2.googleapis.com/token';
    private const USERINFO = 'https://www.googleapis.com/oauth2/v3/userinfo';
    private const CALENDAR_API = 'https://www.googleapis.com/calendar/v3';
    private const SCOPES = 'https://www.googleapis.com/auth/calendar https://www.googleapis.com/auth/userinfo.email';
    private const CACHE_KEY = 'google_oauth_access_token';
    private const DEDICATED_CALENDAR = 'Citas Consultorio';

    /**
     * ¿Está configurado el cliente OAuth (credenciales en .env)?
     */
    public function isAvailable(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    /**
     * URL a la que se envía al usuario para que autorice el acceso a su calendario.
     */
    public function authUrl(string $state): string
    {
        return self::AUTH.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline', // necesario para obtener refresh_token
            'prompt' => 'consent',      // fuerza el consentimiento → siempre devuelve refresh_token
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Intercambia el código de autorización por tokens y persiste la conexión.
     */
    public function handleCallback(string $code): void
    {
        $response = Http::asForm()->timeout(30)->post(self::TOKEN, [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Google rechazó la autorización: '.($response->json('error_description') ?? $response->body())
            );
        }

        $refresh = $response->json('refresh_token');
        $access = $response->json('access_token');

        // Google solo devuelve refresh_token la primera vez que se consiente. Si
        // no vino, reusa el guardado; si tampoco hay, pide reconectar.
        if (blank($refresh)) {
            $refresh = Settings::googleOAuth()['refresh_token'] ?? null;
            if (blank($refresh)) {
                throw new RuntimeException('Google no devolvió un token de actualización. Intenta conectar de nuevo.');
            }
        }

        Settings::setGoogleOAuth($refresh, $this->fetchEmail($access));

        if (filled($access)) {
            Cache::put(self::CACHE_KEY, $access, 3300);
        }

        // Deja listo el calendario dedicado "Citas Consultorio".
        $this->ensureDedicatedCalendar();
    }

    /**
     * Garantiza que exista el calendario dedicado a las citas y devuelve su id.
     * Reutiliza el guardado o uno con el mismo nombre; si no, lo crea. Idempotente.
     */
    public function ensureDedicatedCalendar(): string
    {
        $token = $this->accessToken();

        // 1) ¿Ya tenemos uno guardado y sigue existiendo?
        $stored = Settings::googleOAuthCalendarId();
        if (filled($stored)) {
            $check = Http::withToken($token)->timeout(20)
                ->get(self::CALENDAR_API.'/calendars/'.rawurlencode($stored));
            if ($check->ok()) {
                return $stored;
            }
        }

        // 2) ¿Existe uno con el mismo nombre? (evita duplicar al reconectar)
        $list = Http::withToken($token)->timeout(20)
            ->get(self::CALENDAR_API.'/users/me/calendarList');
        if ($list->ok()) {
            foreach ($list->json('items', []) as $cal) {
                if (($cal['summary'] ?? null) === self::DEDICATED_CALENDAR && filled($cal['id'] ?? null)) {
                    Settings::setGoogleOAuthCalendar($cal['id']);

                    return $cal['id'];
                }
            }
        }

        // 3) Crearlo.
        $response = Http::withToken($token)->timeout(30)
            ->post(self::CALENDAR_API.'/calendars', [
                'summary' => self::DEDICATED_CALENDAR,
                'timeZone' => Settings::googleTimezone(),
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'No se pudo crear el calendario de citas: '.($response->json('error.message') ?? $response->body())
            );
        }

        $id = $response->json('id');
        Settings::setGoogleOAuthCalendar($id);

        return $id;
    }

    /**
     * Access token vigente (cacheado ~55 min); lo renueva con el refresh_token.
     */
    public function accessToken(): string
    {
        return Cache::remember(self::CACHE_KEY, 3300, function () {
            $oauth = Settings::googleOAuth();
            if (! $oauth) {
                throw new RuntimeException('El calendario de Google no está conectado.');
            }

            $response = Http::asForm()->timeout(30)->post(self::TOKEN, [
                'refresh_token' => $oauth['refresh_token'],
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'grant_type' => 'refresh_token',
            ]);

            if ($response->failed()) {
                throw new RuntimeException(
                    'No se pudo renovar el acceso a Google: '.($response->json('error_description') ?? $response->body())
                );
            }

            return $response->json('access_token');
        });
    }

    /**
     * Olvida el token cacheado (al desconectar).
     */
    public function forgetCachedToken(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function fetchEmail(?string $accessToken): ?string
    {
        if (blank($accessToken)) {
            return null;
        }

        $response = Http::withToken($accessToken)->timeout(20)->get(self::USERINFO);

        return $response->ok() ? $response->json('email') : null;
    }
}
