<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;
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
    private const KEY_MP_ACCESS_TOKEN = 'mp_access_token';
    private const KEY_MP_PUBLIC_KEY = 'mp_public_key';
    private const KEY_MP_TEST_ACCESS_TOKEN = 'mp_test_access_token';
    private const KEY_MP_TEST_PUBLIC_KEY = 'mp_test_public_key';
    private const KEY_MP_TEST_MODE = 'mp_test_mode';
    private const KEY_WA_TEST_NUMBERS = 'whatsapp_test_numbers';

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
            'bot_name' => self::get('bot_name') ?: 'Lore',
            'clinic_name' => self::get('clinic_name') ?: 'Consultorio Dra. Jasmin Blanco',
            // Dirección tal como la escribió la doctora en sus propias plantillas
            // de WhatsApp (notificacion_2_dias, notificacion_agendamiento y
            // recordatorio_de_reserva_3_horas_antes coinciden en el 82-46).
            'clinic_address' => self::get('clinic_address') ?: 'Cra 16 A #82-46 Consultorio 303, Bogotá',
            'clinic_hours' => self::get('clinic_hours') ?: 'Lunes a viernes de 8:00 a.m. a 6:00 p.m. y sábados de 9:00 a.m. a 1:00 p.m.',
            'clinic_payment' => self::get('clinic_payment') ?: 'Efectivo, tarjeta débito y crédito, y transferencia bancaria.',
            'clinic_payment_link' => self::get('clinic_payment_link') ?: '',
            'clinic_landing' => self::get('clinic_landing') ?: '',
            'bot_persona' => self::get('bot_persona') ?: '',
        ];
    }

    /**
     * @param  array<string,?string>  $config
     */
    public static function setBotConfig(array $config): void
    {
        foreach (['bot_name', 'clinic_name', 'clinic_address', 'clinic_hours', 'clinic_payment', 'clinic_payment_link', 'clinic_landing', 'bot_persona'] as $key) {
            if (array_key_exists($key, $config)) {
                self::put($key, $config[$key]);
            }
        }
    }

    /**
     * Credenciales de Mercado Pago (pagos en línea).
     *
     * Se guardan cifradas, como la de Anthropic. El ACCESS TOKEN es el que usa
     * la API para crear preferencias y consultar pagos; la PUBLIC KEY solo hace
     * falta si algún día se embebe el checkout en el navegador.
     *
     * Hay DOS juegos guardados a la vez, producción y prueba, porque conviven:
     * el consultorio cobra de verdad y aun así se necesita poder ensayar el
     * circuito completo (link → pago → confirmación del bot) sin mover dinero.
     * `mp_test_mode` decide cuál se usa, y como todo el sistema pide la
     * credencial por `mpAccessToken()`, ese interruptor cambia de golpe el bot,
     * el cron de pagos pendientes y la pantalla de ajustes.
     */
    public static function mpAccessToken(): ?string
    {
        return self::mpTestMode()
            ? self::mpTestAccessToken()
            : self::mpLiveAccessToken();
    }

    public static function mpPublicKey(): ?string
    {
        return self::mpTestMode()
            ? self::decrypt(self::KEY_MP_TEST_PUBLIC_KEY)
            : self::decrypt(self::KEY_MP_PUBLIC_KEY);
    }

    /** Credencial de producción, la que cobra de verdad. */
    public static function mpLiveAccessToken(): ?string
    {
        return self::decrypt(self::KEY_MP_ACCESS_TOKEN);
    }

    public static function mpLivePublicKey(): ?string
    {
        return self::decrypt(self::KEY_MP_PUBLIC_KEY);
    }

    /** Credencial de prueba (`TEST-…`): mismos endpoints, pero sin dinero real. */
    public static function mpTestAccessToken(): ?string
    {
        return self::decrypt(self::KEY_MP_TEST_ACCESS_TOKEN);
    }

    public static function mpTestPublicKey(): ?string
    {
        return self::decrypt(self::KEY_MP_TEST_PUBLIC_KEY);
    }

    /**
     * ¿Se está cobrando en modo prueba?
     *
     * Exige que además exista la credencial de prueba: si el interruptor
     * quedara encendido sin token, el bot se quedaría sin pasarela y volvería a
     * creerle a la paciente que pagó. Es preferible que el modo prueba se apague
     * solo antes que dejar ese hueco abierto.
     */
    public static function mpTestMode(): bool
    {
        return self::get(self::KEY_MP_TEST_MODE) === '1'
            && filled(self::mpTestAccessToken());
    }

    public static function setMpTestMode(bool $enabled): void
    {
        self::put(self::KEY_MP_TEST_MODE, $enabled ? '1' : '0');
    }

    /**
     * Guarda un juego de credenciales. Los campos vacíos no borran lo que ya
     * había: la pantalla nunca devuelve el token guardado, así que un formulario
     * en blanco significa "déjalo como está", no "bórralo".
     */
    public static function setMercadoPago(?string $accessToken, ?string $publicKey, bool $test = false): void
    {
        $tokenKey = $test ? self::KEY_MP_TEST_ACCESS_TOKEN : self::KEY_MP_ACCESS_TOKEN;
        $publicKeyKey = $test ? self::KEY_MP_TEST_PUBLIC_KEY : self::KEY_MP_PUBLIC_KEY;

        if (filled($accessToken)) {
            self::put($tokenKey, Crypt::encryptString($accessToken));
        }

        if (filled($publicKey)) {
            self::put($publicKeyKey, Crypt::encryptString($publicKey));
        }
    }

    /** Desconecta del todo: se van los dos juegos y se apaga el modo prueba. */
    public static function clearMercadoPago(): void
    {
        self::put(self::KEY_MP_ACCESS_TOKEN, null);
        self::put(self::KEY_MP_PUBLIC_KEY, null);
        self::clearMercadoPagoTest();
    }

    /** Quita solo las credenciales de prueba (y con ellas el modo prueba). */
    public static function clearMercadoPagoTest(): void
    {
        self::put(self::KEY_MP_TEST_ACCESS_TOKEN, null);
        self::put(self::KEY_MP_TEST_PUBLIC_KEY, null);
        self::put(self::KEY_MP_TEST_MODE, '0');
    }

    public static function hasMercadoPago(): bool
    {
        return filled(self::mpAccessToken());
    }

    /**
     * Interruptor general del bot en WhatsApp.
     *
     * Es distinto de la pausa por chat (`conversations.bot_enabled`): esto apaga
     * a Lore para TODAS las pacientes de una vez. Los mensajes entrantes se
     * siguen guardando y apareciendo en la bandeja; lo único que no ocurre es la
     * respuesta automática.
     *
     * Por defecto está APAGADO a propósito: conectar el webhook no debe empezar
     * a escribirle a pacientes reales sin que alguien lo decida.
     */
    public static function whatsappBotEnabled(): bool
    {
        return self::get('whatsapp_bot_enabled') === '1';
    }

    public static function setWhatsappBotEnabled(bool $enabled): void
    {
        self::put('whatsapp_bot_enabled', $enabled ? '1' : '0');
    }

    /**
     * Lista blanca para probar el canal en vivo.
     *
     * Mientras tenga números, Lore SOLO le responde a esos; a cualquier otra
     * paciente se le guarda el mensaje en la bandeja y no se le contesta. Vacía
     * (lo normal) = responde a todas. NO afecta a los recordatorios de cita,
     * que salen por su propio comando programado.
     *
     * @return array<int,string> Números normalizados a solo dígitos.
     */
    public static function whatsappTestNumbers(): array
    {
        return self::normalizePhones((string) self::get(self::KEY_WA_TEST_NUMBERS));
    }

    /** Acepta números separados por coma, punto y coma o saltos de línea. */
    public static function setWhatsappTestNumbers(?string $raw): void
    {
        $numbers = self::normalizePhones((string) $raw);

        self::put(self::KEY_WA_TEST_NUMBERS, $numbers ? implode(',', $numbers) : null);
    }

    /**
     * ¿Está este número en la lista? Compara por los últimos 10 dígitos, así da
     * igual que se haya escrito con indicativo o sin él: Meta entrega el `from`
     * como `573123652269` y uno tiende a escribir `312 365 2269`.
     *
     * @param  array<int,string>  $list  Ya normalizada (`whatsappTestNumbers()`).
     */
    public static function phoneInList(string $phone, array $list): bool
    {
        $target = self::normalizePhone($phone);

        if (strlen($target) < 8) {
            return false;
        }

        foreach ($list as $allowed) {
            if (str_ends_with($target, substr($allowed, -10)) || str_ends_with($allowed, substr($target, -10))) {
                return true;
            }
        }

        return false;
    }

    public static function normalizePhone(string $phone): string
    {
        return (string) preg_replace('/\D+/', '', $phone);
    }

    /**
     * Deja solo dígitos, descarta lo que sea demasiado corto para ser un
     * teléfono (evita que un dedazo abra la puerta a media Colombia) y quita
     * duplicados.
     *
     * @return array<int,string>
     */
    private static function normalizePhones(string $raw): array
    {
        // Solo coma, punto y coma o salto de línea separan: el espacio NO, porque
        // la gente escribe "+57 312 365 2269" y partirlo por ahí deja pedazos
        // demasiado cortos que el filtro de longitud se come.
        $numbers = array_filter(
            array_map(fn (string $p) => self::normalizePhone($p), preg_split('/[,;\r\n]+/', $raw) ?: []),
            fn (string $p) => strlen($p) >= 8,
        );

        return array_values(array_unique($numbers));
    }

    /** Valor de la valoración que se le cobra a la paciente para apartar el cupo. */
    public static function valoracionAmount(): int
    {
        return (int) (self::get('valoracion_amount') ?: 75000);
    }

    public static function setValoracionAmount(?int $amount): void
    {
        if ($amount) {
            self::put('valoracion_amount', (string) $amount);
        }
    }

    private static function decrypt(string $key): ?string
    {
        $stored = self::get($key);

        if (blank($stored)) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Recordatorios de cita por WhatsApp.
     *
     * La plantilla se deja configurable porque cada clínica registra la suya en
     * su propio WhatsApp Manager. Si no hay plantilla, los recordatorios se
     * mandan como texto libre, que Meta SOLO entrega si el paciente escribió en
     * las últimas 24h (sirve para probar, no para producción).
     *
     * @return array{enabled:bool, template:string, language:string, country_code:string}
     */
    public static function reminderConfig(): array
    {
        return [
            'enabled' => self::get('reminders_enabled', '1') === '1',
            'template' => self::get('reminder_template') ?: '',
            'language' => self::get('reminder_language') ?: 'es',
            'country_code' => self::get('reminder_country_code') ?: '57',
        ];
    }

    /**
     * Un teléfono en el formato que exige WhatsApp (con indicativo), o null si
     * no hay uno utilizable.
     *
     * Meta EXIGE el indicativo de país. Un móvil colombiano de 10 dígitos
     * enviado tal cual se acepta con un 200 y rebota después con
     * `131026 Message undeliverable`: el fallo no se ve al enviar, solo aparece
     * más tarde en `delivery_failures`. En producción, 67 de los 82 teléfonos
     * están guardados sin el 57.
     *
     * El indicativo vive en `reminderConfig()`, NO en `botConfig()` — esa clave
     * no existe ahí y devolvía null, o sea prefijo vacío y el número igual de
     * roto que antes.
     */
    public static function phoneWithCountryCode(?string $raw, ?string $codigoPais = null): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $raw);

        if (strlen($digitos) < 10) {
            return null;
        }

        // Más de 10 dígitos = ya trae indicativo (57…, 1…, 34…); se respeta.
        if (strlen($digitos) > 10) {
            return $digitos;
        }

        return ($codigoPais ?: self::reminderConfig()['country_code']).$digitos;
    }

    /**
     * Mensaje de reactivación para quien preguntó y nunca agendó.
     *
     * `hours` es el silencio que tiene que acumular la paciente antes de que se
     * le escriba. Está en HORAS y no en días porque la doctora lo quiso corto
     * (24-48 h), y a esa escala "1 día" y "2 días" son decisiones distintas.
     *
     * `max_per_run` es el freno importante: la marca de enviado nace vacía, así
     * que la PRIMERA corrida ve como pendientes todas las conversaciones frías
     * del histórico. Sin tope, encender esto le manda una promoción de golpe a
     * decenas de personas que llevan meses sin escribir — la mejor forma de que
     * reporten el número y Meta baje la calidad de la línea. Con tope, el atasco
     * se reparte a lo largo de las horas siguientes.
     *
     * `min_inbound_at` es la LÍNEA DE SALIDA: solo entran conversaciones cuyo
     * último mensaje de la paciente sea posterior a esa fecha. Existe porque al
     * encender esto había 104 conversaciones frías acumuladas y la doctora quiso
     * empezar solo con las nuevas.
     *
     * Se resuelve con una fecha y NO marcando esas 104 filas como "ya enviado",
     * que era la otra forma obvia: eso habría dejado en la base 104
     * reactivaciones que nunca salieron, indistinguibles de las reales en
     * cualquier auditoría posterior. Además así es reversible — se borra el
     * ajuste y la cola vuelve — y no se toca ni una fila de datos.
     *
     * @return array{enabled:bool,hours:int,template:string,language:string,max_per_run:int,min_inbound_at:?Carbon}
     */
    public static function reactivationConfig(): array
    {
        $desde = self::get('reactivation_min_inbound_at');

        return [
            'enabled' => self::get('reactivation_enabled', '0') === '1',
            'hours' => max(1, (int) (self::get('reactivation_hours') ?: 48)),
            'template' => self::get('reactivation_template') ?: 'reactivacion_lead',
            'language' => self::get('reactivation_language') ?: 'es',
            'max_per_run' => max(1, (int) (self::get('reactivation_max_per_run') ?: 20)),
            'min_inbound_at' => filled($desde) ? Carbon::parse($desde) : null,
        ];
    }

    /**
     * Etapas del pipeline cuyos leads NO deben recibir reactivación, en
     * minúsculas y sin tildes.
     *
     * La exclusión por «ya tiene cita» solo ve la tabla de citas. Si la doctora
     * arrastra a alguien a «Agendado» o «Cerrado» en el Kanban sin que llegue a
     * existir la cita —o lo marca «Perdido» a propósito— seguía entrando en la
     * cola y recibía un «¿aún podemos ayudarte?» que no venía a cuento. Pasa
     * justo con quien escribe preguntando por algo de una cita anterior.
     *
     * @return array<int,string>
     */
    public static function reactivationExcludedStages(): array
    {
        $raw = (string) (self::get('reactivation_excluded_stages') ?: 'agendado,en valoracion,cerrado,perdido');

        return collect(preg_split('/[,;\n]+/', $raw))
            ->map(fn ($s) => trim(strtr(mb_strtolower((string) $s), [
                'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            ])))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $config
     */
    public static function setReactivationConfig(array $config): void
    {
        if (array_key_exists('enabled', $config)) {
            self::put('reactivation_enabled', $config['enabled'] ? '1' : '0');
        }

        foreach ([
            'hours' => 'reactivation_hours',
            'template' => 'reactivation_template',
            'language' => 'reactivation_language',
            'max_per_run' => 'reactivation_max_per_run',
        ] as $campo => $key) {
            if (array_key_exists($campo, $config)) {
                self::put($key, $config[$campo] === null ? null : (string) $config[$campo]);
            }
        }

        if (array_key_exists('min_inbound_at', $config)) {
            $valor = $config['min_inbound_at'];

            self::put(
                'reactivation_min_inbound_at',
                blank($valor) ? null : Carbon::parse($valor)->toDateTimeString(),
            );
        }
    }

    /**
     * @param  array<string,mixed>  $config
     */
    public static function setReminderConfig(array $config): void
    {
        if (array_key_exists('enabled', $config)) {
            self::put('reminders_enabled', $config['enabled'] ? '1' : '0');
        }

        foreach (['template' => 'reminder_template', 'language' => 'reminder_language', 'country_code' => 'reminder_country_code'] as $campo => $key) {
            if (array_key_exists($campo, $config)) {
                self::put($key, $config[$campo]);
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
     * Plantilla del aviso «tu cita ha sido agendada» (distinta de la de
     * recordatorio: aquí se confirma, no se recuerda).
     *
     * Creada en Meta el 2026-08-05 y aprobada aparte. Mientras esté pendiente,
     * el envío falla y quien la usa cae en la de recordatorio, así que no hace
     * falta tocar nada el día que la aprueben.
     */
    public static function confirmationTemplate(): string
    {
        return self::get('confirmation_template') ?: 'cita_agendada';
    }

    /**
     * Plantilla del aviso «tu cita fue reprogramada».
     *
     * Va aparte de la de confirmación porque no dice lo mismo: a quien le
     * mueven la hora, «tu cita ha sido agendada» le suena a cita nueva y puede
     * dejarle en la cabeza las dos fechas. Mientras Meta la tenga pendiente,
     * quien la usa cae en la de confirmación, que ya está aprobada y al menos
     * lleva la fecha NUEVA.
     */
    public static function rescheduleTemplate(): string
    {
        return self::get('reschedule_template') ?: 'cita_reprogramada';
    }

    /**
     * ¿Se le puede escribir a este número de forma automática, sin que nadie
     * del consultorio lo haya pedido?
     *
     * Une los DOS frenos que ya existían pero que solo respetaba el flujo de
     * respuestas: el interruptor general y la lista blanca de pruebas. Los
     * envíos programados (recordatorios, aviso de pago) los ignoraban, así que
     * apagar el bot no los detenía y la lista de pruebas no los limitaba.
     *
     * No aplica a lo que la doctora envía a mano desde la bandeja: eso es una
     * persona decidiendo, no automatización.
     */
    public static function autoMessagingAllows(?string $phone): bool
    {
        if (! self::whatsappBotEnabled()) {
            return false;
        }

        $lista = self::whatsappTestNumbers();

        // Lista vacía = sin restricción (comportamiento normal).
        return $lista === [] || self::phoneInList((string) $phone, $lista);
    }

    /**
     * Descanso diario (almuerzo), con el mismo formato que `scheduleHours()`:
     * día de la semana => ['HH:MM' inicio, 'HH:MM' fin] o null si ese día no hay.
     *
     * Existe porque el horario de atención es UNA franja continua, así que sin
     * esto la hora del almuerzo se ofrecía como cualquier otra.
     *
     * @return array<int,array{0:string,1:string}|null>
     */
    public static function scheduleBreaks(): array
    {
        $stored = self::get('schedule_breaks');
        if (filled($stored)) {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                $out = [];
                foreach ($decoded as $day => $window) {
                    $out[(int) $day] = is_array($window) && count($window) === 2 ? [$window[0], $window[1]] : null;
                }

                return $out;
            }
        }

        // Default: almuerzo de la doctora, 12:00–13:00, TODOS los días que abre.
        // Ojo con el sábado: como cierra a las 13:00, el descanso se come su
        // última hora y en la práctica la jornada termina a las 12:00. Es
        // deliberado —la doctora almuerza igual— y por eso se modela como
        // descanso y no adelantando el cierre: si algún día amplía el horario
        // del sábado, la hora del almuerzo sigue protegida sola.
        return [
            0 => null,
            1 => ['12:00', '13:00'],
            2 => ['12:00', '13:00'],
            3 => ['12:00', '13:00'],
            4 => ['12:00', '13:00'],
            5 => ['12:00', '13:00'],
            6 => ['12:00', '13:00'],
        ];
    }

    /**
     * Dominio del sitio web de la clínica, sin protocolo ni barra final.
     *
     * Se usa para reconocer de qué página llegó la paciente: el botón de
     * WhatsApp de la web prellena el mensaje con la URL que estaba viendo.
     */
    public static function websiteDomain(): string
    {
        $raw = (string) (self::get('website_domain') ?: 'drajasminblanco.com');

        return trim(preg_replace('#^https?://(www\.)?#i', '', $raw), " \t\n\r\0\x0B/");
    }

    /**
     * Cuánto dura una cita agendada por el bot cuando el servicio no lo dice.
     *
     * Son 60 porque la valoración —que es lo que agenda el bot— dura una hora.
     * Las citas de 30 minutos que hay en la agenda las mete la doctora a mano;
     * la plataforma nunca debe agendar menos de esto por su cuenta.
     */
    public static function defaultAppointmentMinutes(): int
    {
        return max(15, (int) (self::get('default_appointment_minutes') ?: 60));
    }

    /**
     * Granularidad de los turnos en minutos (cada cuánto se ofrece un horario).
     *
     * Por defecto va pegada a la duración de la cita: ofrecer huecos cada 30
     * minutos para una valoración de 60 partía la agenda en dos —reservar las
     * 9:30 dejaba muerta la media hora anterior— y la doctora veía
     * «disponibilidad cada 30 minutos» para algo que le ocupa una hora.
     */
    public static function scheduleSlotMinutes(): int
    {
        return (int) (self::get('schedule_slot_minutes') ?: self::defaultAppointmentMinutes());
    }

    /**
     * Días sueltos en los que el consultorio NO atiende: festivos, vacaciones,
     * un congreso, un puente.
     *
     * Es distinto de `scheduleHours()`, que cierra días de la SEMANA (el
     * domingo, siempre). Aquí van fechas concretas, y por eso se guardan como
     * un mapa `Y-m-d => motivo`: la clave da la búsqueda directa y quita los
     * duplicados sola, y el motivo es para que la clínica entienda su propia
     * lista dentro de seis meses. A la paciente no se le cuenta el motivo.
     *
     * @return array<string,string>  '2026-12-25' => 'Navidad'
     */
    public static function closedDays(): array
    {
        $stored = self::get('schedule_closed_days');

        if (blank($stored)) {
            return [];
        }

        $decoded = json_decode($stored, true);

        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $fecha => $motivo) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fecha)) {
                $out[(string) $fecha] = (string) $motivo;
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * @param  array<string,string>  $days  'Y-m-d' => motivo
     */
    public static function setClosedDays(array $days): void
    {
        $limpio = [];
        $hoy = now()->format('Y-m-d');

        foreach ($days as $fecha => $motivo) {
            $fecha = trim((string) $fecha);

            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
                continue;
            }

            // Las fechas ya pasadas se descartan al guardar: cerrar un día que
            // ya ocurrió no cambia nada y la lista crecería para siempre.
            if ($fecha < $hoy) {
                continue;
            }

            $limpio[$fecha] = mb_substr(trim((string) $motivo), 0, 120);
        }

        ksort($limpio);

        self::put('schedule_closed_days', json_encode($limpio, JSON_UNESCAPED_UNICODE));
    }

    /** ¿El consultorio está cerrado ese día concreto? */
    public static function isClosedOn(mixed $date): bool
    {
        return array_key_exists(self::asDay($date), self::closedDays());
    }

    /** El motivo del cierre, si lo hay. Uso interno: no se le dice a la paciente. */
    public static function closedReason(mixed $date): ?string
    {
        $motivo = self::closedDays()[self::asDay($date)] ?? null;

        return blank($motivo) ? null : $motivo;
    }

    /** Acepta un Carbon, un DateTime o una cadena, y devuelve 'Y-m-d'. */
    private static function asDay(mixed $date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return substr((string) $date, 0, 10);
    }
}
