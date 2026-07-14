<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\GoogleCalendarService;
use App\Services\GoogleOAuthService;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GoogleCalendarSettingsController extends Controller
{
    public function __construct(private readonly GoogleOAuthService $oauth)
    {
    }

    public function edit(): Response
    {
        return Inertia::render('settings/calendar', [
            'configured' => Settings::hasGoogleCalendar(),
            'hasServiceAccount' => Settings::googleServiceAccount() !== null,
            'serviceAccountEmail' => Settings::googleServiceAccountEmail(),
            'calendarId' => Settings::googleCalendarId(),
            'timezone' => Settings::googleTimezone(),
            // OAuth "un clic"
            'oauthAvailable' => $this->oauth->isAvailable(),
            'googleConnected' => Settings::hasGoogleOAuth(),
            'googleEmail' => Settings::googleOAuthEmail(),
        ]);
    }

    /**
     * Envía al usuario a Google para autorizar el acceso a su calendario.
     */
    public function connect(Request $request): RedirectResponse
    {
        if (! $this->oauth->isAvailable()) {
            return back()->with('error', 'La conexión con Google no está configurada (faltan las credenciales OAuth en el servidor).');
        }

        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away($this->oauth->authUrl($state));
    }

    /**
     * Google regresa aquí tras el consentimiento: intercambia el código y guarda.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()->route('calendar.edit')->with('error', 'Se canceló la conexión con Google.');
        }

        $expected = $request->session()->pull('google_oauth_state');
        if (blank($expected) || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect()->route('calendar.edit')->with('error', 'La conexión con Google no se pudo verificar. Inténtalo de nuevo.');
        }

        try {
            $this->oauth->handleCallback((string) $request->query('code'));
        } catch (\Throwable $e) {
            return redirect()->route('calendar.edit')->with('error', 'No se pudo conectar con Google: '.$e->getMessage());
        }

        return redirect()->route('calendar.edit')->with('success', '¡Google Calendar conectado! Tus citas se sincronizarán con tu calendario.');
    }

    /**
     * Desconecta el calendario conectado por OAuth.
     */
    public function disconnectGoogle(): RedirectResponse
    {
        Settings::clearGoogleOAuth();
        $this->oauth->forgetCachedToken();

        return back()->with('success', 'Se desconectó tu Google Calendar.');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'service_account_json' => ['nullable', 'string', $this->serviceAccountRule()],
            'calendar_id' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        Settings::setGoogle(
            $data['service_account_json'] ?? null,
            $data['calendar_id'] ?? null,
            $data['timezone'] ?? null,
        );

        return back()->with('success', 'Configuración de Google Calendar guardada.');
    }

    public function destroy(): RedirectResponse
    {
        Settings::clearGoogle();

        return back()->with('success', 'Conexión con Google Calendar eliminada.');
    }

    /**
     * Prueba la conexión: lee la metadata del calendario configurado.
     */
    public function test(): RedirectResponse
    {
        try {
            $name = GoogleCalendarService::fromConfig()->ping();
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "¡Conexión exitosa! Calendario: «{$name}».");
    }

    /**
     * Valida que el JSON pegado sea una credencial de cuenta de servicio válida.
     */
    private function serviceAccountRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $decoded = json_decode((string) $value, true);

            if (! is_array($decoded)) {
                $fail('El JSON de la cuenta de servicio no es válido.');

                return;
            }

            if (($decoded['type'] ?? null) !== 'service_account') {
                $fail('El archivo debe ser una credencial de tipo "service_account".');

                return;
            }

            foreach (['client_email', 'private_key', 'token_uri'] as $field) {
                if (blank($decoded[$field] ?? null)) {
                    $fail("Al JSON le falta el campo «{$field}».");

                    return;
                }
            }
        };
    }
}
