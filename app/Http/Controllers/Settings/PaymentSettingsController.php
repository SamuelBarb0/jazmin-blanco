<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\MercadoPagoService;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Conexión con Mercado Pago (pagos en línea).
 *
 * Igual que la de Anthropic: las credenciales se pegan por pantalla y se guardan
 * cifradas en la tabla `settings`, no en el .env. Apenas hay un access token
 * válido, el asistente empieza a generar links propios por paciente y a
 * comprobar el pago de verdad, sin tocar código.
 *
 * Conviven dos juegos de credenciales, producción y prueba, y un interruptor
 * elige cuál manda. Así se puede ensayar el cobro completo en el servidor real
 * sin desconectar las de producción ni mover dinero.
 */
class PaymentSettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/pagos', [
            'connected' => Settings::hasMercadoPago(),
            // Solo la cola, para que se vea que hay credencial sin exponerla.
            'tokenHint' => self::hint(Settings::mpAccessToken()),
            'hasPublicKey' => filled(Settings::mpPublicKey()),
            'liveHint' => self::hint(Settings::mpLiveAccessToken()),
            'hasLivePublicKey' => filled(Settings::mpLivePublicKey()),
            'testHint' => self::hint(Settings::mpTestAccessToken()),
            'hasTestPublicKey' => filled(Settings::mpTestPublicKey()),
            'testMode' => Settings::mpTestMode(),
            'valoracionAmount' => Settings::valoracionAmount(),
            'methods' => MercadoPagoService::MEDIOS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'access_token' => ['nullable', 'string', 'max:255'],
            'public_key' => ['nullable', 'string', 'max:255'],
            'test_access_token' => ['nullable', 'string', 'max:255'],
            'test_public_key' => ['nullable', 'string', 'max:255'],
            'test_mode' => ['boolean'],
            'valoracion_amount' => ['nullable', 'integer', 'min:1000', 'max:100000000'],
        ]);

        // Confundir las cajas es el error caro: una credencial de prueba en el
        // campo de producción deja de cobrar de verdad sin que nadie lo note, y
        // al revés se cobra en serio creyendo que se está ensayando.
        self::guardPrefix($data['access_token'] ?? null, 'TEST-', 'access_token',
            'Ese es un access token de PRUEBA (empieza por TEST-). Va en el campo de credenciales de prueba, no en el de producción.');
        self::guardPrefix($data['test_access_token'] ?? null, 'APP_USR-', 'test_access_token',
            'Ese es un access token de PRODUCCIÓN (empieza por APP_USR-). Va en el campo de producción, no en el de prueba.');

        $quierePrueba = (bool) ($data['test_mode'] ?? false);

        // Se comprueba antes de guardar nada, para no dejar el formulario a medio
        // aplicar cuando se pide modo prueba sin credencial que lo sostenga.
        if ($quierePrueba && blank($data['test_access_token'] ?? null) && blank(Settings::mpTestAccessToken())) {
            throw ValidationException::withMessages([
                'test_access_token' => 'Para activar el modo prueba primero hay que pegar el access token de prueba.',
            ]);
        }

        Settings::setMercadoPago($data['access_token'] ?? null, $data['public_key'] ?? null);
        Settings::setMercadoPago($data['test_access_token'] ?? null, $data['test_public_key'] ?? null, test: true);
        Settings::setValoracionAmount($data['valoracion_amount'] ?? null);
        Settings::setMpTestMode($quierePrueba);

        return back()->with('success', $quierePrueba
            ? 'Datos de pago guardados. MODO PRUEBA activo: los links no cobran dinero real.'
            : 'Datos de pago guardados. Cobrando con las credenciales de producción.');
    }

    public function destroy(): RedirectResponse
    {
        Settings::clearMercadoPago();

        return back()->with('success', 'Se desconectó Mercado Pago. El asistente vuelve a pedir el pago sin comprobarlo.');
    }

    /** Borra solo el juego de prueba; producción sigue intacta. */
    public function destroyTest(): RedirectResponse
    {
        Settings::clearMercadoPagoTest();

        return back()->with('success', 'Se quitaron las credenciales de prueba. Vuelve a cobrar con las de producción.');
    }

    /** Comprueba la credencial consultando la cuenta. No crea nada ni mueve dinero. */
    public function test(): RedirectResponse
    {
        if (! Settings::hasMercadoPago()) {
            return back()->with('error', 'Primero guarda el access token.');
        }

        $entorno = Settings::mpTestMode() ? 'de PRUEBA' : 'de producción';

        try {
            $cuenta = MercadoPagoService::fromConfig()->ping();
        } catch (Throwable $e) {
            return back()->with('error', "No se pudo conectar con Mercado Pago (credencial $entorno): ".$e->getMessage());
        }

        // Si la cuenta no es de Colombia, los cobros en pesos fallarían al pagar.
        if (($cuenta['site_id'] ?? null) !== 'MCO') {
            return back()->with('error', 'La credencial es válida pero la cuenta no es de Colombia (país '
                .($cuenta['site_id'] ?? 'desconocido').'). Los cobros en pesos colombianos no funcionarán.');
        }

        return back()->with('success', "Conexión correcta con la cuenta ".($cuenta['nickname'] ?? 'de Mercado Pago')
            ." usando la credencial $entorno. El asistente ya puede generar links de pago.");
    }

    /** Cola del token, lo justo para reconocerlo sin dejarlo a la vista. */
    private static function hint(?string $token): ?string
    {
        return filled($token) ? '…'.mb_substr($token, -6) : null;
    }

    private static function guardPrefix(?string $token, string $prefijo, string $campo, string $mensaje): void
    {
        if (filled($token) && str_starts_with(trim($token), $prefijo)) {
            throw ValidationException::withMessages([$campo => $mensaje]);
        }
    }
}
