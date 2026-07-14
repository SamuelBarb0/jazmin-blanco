<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Acceso sencillo a la configuración de la aplicación (clave-valor en BD).
 * La API key de Anthropic y la credencial de Google se guardan cifradas.
 */
class Settings
{
    private const KEY_ANTHROPIC = 'anthropic_api_key';
    private const KEY_MODEL = 'anthropic_model';
    private const KEY_GOOGLE_SA = 'google_service_account';
    private const KEY_GOOGLE_CALENDAR = 'google_calendar_id';
    private const KEY_GOOGLE_TIMEZONE = 'google_timezone';
    private const KEY_GOOGLE_OAUTH = 'google_oauth';

    public static function get(string $key, ?string $default = null): ?string
    {
        return Setting::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function anthropicKey(): ?string
    {
        $stored = self::get(self::KEY_ANTHROPIC);

        if (filled($stored)) {
            try {
                return Crypt::decryptString($stored);
            } catch (Throwable) {
                // valor corrupto: ignorar y caer al .env
            }
        }

        return config('services.anthropic.key');
    }

    public static function anthropicModel(): string
    {
        return self::get(self::KEY_MODEL)
            ?: config('services.anthropic.model', 'claude-opus-4-8');
    }

    public static function setAnthropic(?string $key, ?string $model): void
    {
        if (filled($key)) {
            self::put(self::KEY_ANTHROPIC, Crypt::encryptString($key));
        }

        if (filled($model)) {
            self::put(self::KEY_MODEL, $model);
        }
    }

    public static function clearAnthropicKey(): void
    {
        self::put(self::KEY_ANTHROPIC, null);
    }

    public static function hasAnthropicKey(): bool
    {
        return filled(self::anthropicKey());
    }

    /**
     * Credencial JSON de la cuenta de servicio de Google (descifrada y decodificada).
     *
     * @return array<string,mixed>|null
     */
    public static function googleServiceAccount(): ?array
    {
        $stored = self::get(self::KEY_GOOGLE_SA);

        if (! filled($stored)) {
            return null;
        }

        try {
            $json = Crypt::decryptString($stored);
        } catch (Throwable) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function googleCalendarId(): ?string
    {
        return self::get(self::KEY_GOOGLE_CALENDAR);
    }

    public static function googleTimezone(): string
    {
        return self::get(self::KEY_GOOGLE_TIMEZONE) ?: 'America/Bogota';
    }

    /**
     * Guarda la credencial de Google. El JSON se cifra; el calendar id y la zona
     * horaria se guardan en claro.
     */
    public static function setGoogle(?string $serviceAccountJson, ?string $calendarId, ?string $timezone): void
    {
        if (filled($serviceAccountJson)) {
            self::put(self::KEY_GOOGLE_SA, Crypt::encryptString($serviceAccountJson));
        }

        if (filled($calendarId)) {
            self::put(self::KEY_GOOGLE_CALENDAR, trim($calendarId));
        }

        if (filled($timezone)) {
            self::put(self::KEY_GOOGLE_TIMEZONE, trim($timezone));
        }
    }

    public static function clearGoogle(): void
    {
        self::put(self::KEY_GOOGLE_SA, null);
        self::put(self::KEY_GOOGLE_CALENDAR, null);
    }

    /**
     * ¿Hay forma de sincronizar el calendario? OAuth del propio usuario (un clic)
     * o cuenta de servicio + id de calendario compartido.
     */
    public static function hasGoogleCalendar(): bool
    {
        return self::hasGoogleOAuth()
            || (self::googleServiceAccount() !== null && filled(self::googleCalendarId()));
    }

    /**
     * Correo de la cuenta de servicio (para mostrar en la UI: "comparte tu calendario con…").
     */
    public static function googleServiceAccountEmail(): ?string
    {
        return self::googleServiceAccount()['client_email'] ?? null;
    }

    /**
     * Credencial OAuth del usuario (calendario propio conectado con un clic).
     * Guarda el refresh_token cifrado + el correo + el id del calendario dedicado.
     *
     * @return array{refresh_token:string, email:?string, calendar_id:?string}|null
     */
    public static function googleOAuth(): ?array
    {
        $stored = self::get(self::KEY_GOOGLE_OAUTH);

        if (! filled($stored)) {
            return null;
        }

        try {
            $json = Crypt::decryptString($stored);
        } catch (Throwable) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) && filled($decoded['refresh_token'] ?? null) ? $decoded : null;
    }

    public static function setGoogleOAuth(string $refreshToken, ?string $email): void
    {
        $existing = self::googleOAuth();

        self::put(self::KEY_GOOGLE_OAUTH, Crypt::encryptString(json_encode([
            'refresh_token' => $refreshToken,
            'email' => $email,
            // Conserva el calendario dedicado ya creado (reconexión sin duplicar).
            'calendar_id' => $existing['calendar_id'] ?? null,
        ])));
    }

    /**
     * Guarda el id del calendario dedicado ("Citas Consultorio") creado en Google.
     */
    public static function setGoogleOAuthCalendar(?string $calendarId): void
    {
        $existing = self::googleOAuth();
        if (! $existing) {
            return;
        }

        $existing['calendar_id'] = $calendarId;
        self::put(self::KEY_GOOGLE_OAUTH, Crypt::encryptString(json_encode($existing)));
    }

    public static function googleOAuthCalendarId(): ?string
    {
        return self::googleOAuth()['calendar_id'] ?? null;
    }

    public static function clearGoogleOAuth(): void
    {
        self::put(self::KEY_GOOGLE_OAUTH, null);
    }

    public static function hasGoogleOAuth(): bool
    {
        return self::googleOAuth() !== null;
    }

    public static function googleOAuthEmail(): ?string
    {
        return self::googleOAuth()['email'] ?? null;
    }

    /**
     * Perfil de la clínica + persona del bot (alimentan el system prompt).
     *
     * @return array<string,string>
     */
    public static function botConfig(): array
    {
        return [
            'clinic_name' => self::get('clinic_name') ?: 'Consultorio Dra. Jasmin Blanco',
            'clinic_address' => self::get('clinic_address') ?: 'Carrera 16A # 82-95, consultorio 303, Bogotá',
            'clinic_hours' => self::get('clinic_hours') ?: 'Lunes a viernes de 8:00 a.m. a 6:00 p.m. y sábados de 9:00 a.m. a 1:00 p.m.',
            'clinic_payment' => self::get('clinic_payment') ?: 'Efectivo, tarjeta débito y crédito, y transferencia bancaria.',
            'bot_persona' => self::get('bot_persona') ?: '',
        ];
    }

    /**
     * @param  array<string,?string>  $config
     */
    public static function setBotConfig(array $config): void
    {
        foreach (['clinic_name', 'clinic_address', 'clinic_hours', 'clinic_payment', 'bot_persona'] as $key) {
            if (array_key_exists($key, $config)) {
                self::put($key, $config[$key]);
            }
        }
    }

    /**
     * Horario de atención estructurado por día de la semana, para calcular los
     * huecos disponibles reales. La clave es el día (Carbon dayOfWeek: 0=domingo
     * … 6=sábado) y el valor es ['HH:MM' apertura, 'HH:MM' cierre] o null si cierra.
     *
     * @return array<int,array{0:string,1:string}|null>
     */
    public static function scheduleHours(): array
    {
        $stored = self::get('schedule_hours');
        if (filled($stored)) {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                // Normaliza las claves a enteros.
                $out = [];
                foreach ($decoded as $day => $window) {
                    $out[(int) $day] = is_array($window) && count($window) === 2 ? [$window[0], $window[1]] : null;
                }

                return $out;
            }
        }

        // Defaults: Lun–Vie 8:00–18:00, Sáb 9:00–13:00, Dom cerrado.
        return [
            0 => null,
            1 => ['08:00', '18:00'],
            2 => ['08:00', '18:00'],
            3 => ['08:00', '18:00'],
            4 => ['08:00', '18:00'],
            5 => ['08:00', '18:00'],
            6 => ['09:00', '13:00'],
        ];
    }

    /**
     * Granularidad de los turnos en minutos (cada cuánto se ofrece un horario).
     */
    public static function scheduleSlotMinutes(): int
    {
        return (int) (self::get('schedule_slot_minutes') ?: 30);
    }
}
