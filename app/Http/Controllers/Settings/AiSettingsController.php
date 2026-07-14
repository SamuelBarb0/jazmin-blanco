<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\AnthropicService;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AiSettingsController extends Controller
{
    private const MODELS = ['claude-opus-4-8', 'claude-sonnet-4-6', 'claude-haiku-4-5'];

    public function edit(): Response
    {
        $key = Settings::anthropicKey();

        return Inertia::render('settings/ia', [
            'configured' => filled($key),
            'keyPreview' => filled($key) ? '••••••••' . substr($key, -4) : null,
            'fromEnv' => blank(Settings::get('anthropic_api_key')) && filled(config('services.anthropic.key')),
            'model' => Settings::anthropicModel(),
            'models' => self::MODELS,
            'bot' => Settings::botConfig(),
        ]);
    }

    public function updateBot(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'clinic_name' => ['nullable', 'string', 'max:255'],
            'clinic_address' => ['nullable', 'string', 'max:500'],
            'clinic_hours' => ['nullable', 'string', 'max:500'],
            'clinic_payment' => ['nullable', 'string', 'max:500'],
            'bot_persona' => ['nullable', 'string', 'max:4000'],
        ]);

        Settings::setBotConfig($data);

        return back()->with('success', 'Perfil del bot actualizado.');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'min:20', 'max:255'],
            'model' => ['required', 'string', 'in:' . implode(',', self::MODELS)],
        ]);

        Settings::setAnthropic($data['api_key'] ?? null, $data['model']);

        return back()->with('success', 'Configuración de IA guardada.');
    }

    public function destroy(): RedirectResponse
    {
        Settings::clearAnthropicKey();

        return back()->with('success', 'API key eliminada.');
    }

    /**
     * Prueba la conexión con Anthropic usando la clave guardada
     * (o una que se envíe sin guardar todavía).
     */
    public function test(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string'],
            'model' => ['nullable', 'string', 'in:' . implode(',', self::MODELS)],
        ]);

        $key = filled($data['api_key'] ?? null) ? $data['api_key'] : Settings::anthropicKey();
        $model = $data['model'] ?? Settings::anthropicModel();

        try {
            (new AnthropicService($key, $model))->ping();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['api_key' => $e->getMessage()]);
        }

        return back()->with('success', '¡Conexión exitosa! La API key funciona.');
    }
}
