<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\BoldService;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Conexión con Bold (pagos en línea).
 *
 * Igual que la de Anthropic: las llaves se pegan por pantalla y se guardan
 * cifradas en la tabla `settings`, no en el .env. Apenas hay una llave válida,
 * el asistente empieza a generar links propios por paciente y a comprobar el
 * pago de verdad, sin tocar código.
 */
class PaymentSettingsController extends Controller
{
    public function edit(): Response
    {
        $identity = Settings::boldIdentityKey();

        return Inertia::render('settings/pagos', [
            'connected' => Settings::hasBold(),
            // Solo la cola, para que se vea que hay una llave sin exponerla.
            'identityHint' => $identity ? '…'.mb_substr($identity, -4) : null,
            'hasSecret' => filled(Settings::boldSecretKey()),
            'valoracionAmount' => Settings::valoracionAmount(),
            'methods' => BoldService::MEDIOS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'identity_key' => ['nullable', 'string', 'max:255'],
            'secret_key' => ['nullable', 'string', 'max:255'],
            'valoracion_amount' => ['nullable', 'integer', 'min:1000', 'max:100000000'],
        ]);

        Settings::setBold($data['identity_key'] ?? null, $data['secret_key'] ?? null);
        Settings::setValoracionAmount($data['valoracion_amount'] ?? null);

        return back()->with('success', 'Datos de pago guardados.');
    }

    public function destroy(): RedirectResponse
    {
        Settings::clearBold();

        return back()->with('success', 'Se desconectó Bold. El asistente vuelve a pedir el pago sin comprobarlo.');
    }

    /** Comprueba la llave creando un link desechable de prueba. */
    public function test(): RedirectResponse
    {
        if (! Settings::hasBold()) {
            return back()->with('error', 'Primero guarda la llave de identidad.');
        }

        try {
            BoldService::fromConfig()->ping();
        } catch (Throwable $e) {
            return back()->with('error', 'No se pudo conectar con Bold: '.$e->getMessage());
        }

        return back()->with('success', 'Conexión con Bold correcta. El asistente ya puede generar links de pago.');
    }
}
