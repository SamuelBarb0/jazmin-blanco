<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\MercadoPagoService;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
 */
class PaymentSettingsController extends Controller
{
    public function edit(): Response
    {
        $token = Settings::mpAccessToken();

        return Inertia::render('settings/pagos', [
            'connected' => Settings::hasMercadoPago(),
            // Solo la cola, para que se vea que hay credencial sin exponerla.
            'tokenHint' => $token ? '…'.mb_substr($token, -6) : null,
            'hasPublicKey' => filled(Settings::mpPublicKey()),
            'valoracionAmount' => Settings::valoracionAmount(),
            'methods' => MercadoPagoService::MEDIOS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'access_token' => ['nullable', 'string', 'max:255'],
            'public_key' => ['nullable', 'string', 'max:255'],
            'valoracion_amount' => ['nullable', 'integer', 'min:1000', 'max:100000000'],
        ]);

        Settings::setMercadoPago($data['access_token'] ?? null, $data['public_key'] ?? null);
        Settings::setValoracionAmount($data['valoracion_amount'] ?? null);

        return back()->with('success', 'Datos de pago guardados.');
    }

    public function destroy(): RedirectResponse
    {
        Settings::clearMercadoPago();

        return back()->with('success', 'Se desconectó Mercado Pago. El asistente vuelve a pedir el pago sin comprobarlo.');
    }

    /** Comprueba la credencial consultando la cuenta. No crea nada ni mueve dinero. */
    public function test(): RedirectResponse
    {
        if (! Settings::hasMercadoPago()) {
            return back()->with('error', 'Primero guarda el access token.');
        }

        try {
            $cuenta = MercadoPagoService::fromConfig()->ping();
        } catch (Throwable $e) {
            return back()->with('error', 'No se pudo conectar con Mercado Pago: '.$e->getMessage());
        }

        // Si la cuenta no es de Colombia, los cobros en pesos fallarían al pagar.
        if (($cuenta['site_id'] ?? null) !== 'MCO') {
            return back()->with('error', 'La credencial es válida pero la cuenta no es de Colombia (país '
                .($cuenta['site_id'] ?? 'desconocido').'). Los cobros en pesos colombianos no funcionarán.');
        }

        return back()->with('success', 'Conexión correcta con la cuenta '.($cuenta['nickname'] ?? 'de Mercado Pago')
            .'. El asistente ya puede generar links de pago.');
    }
}
