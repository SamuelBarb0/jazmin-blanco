<?php

namespace Tests\Feature;

use App\Services\AnthropicService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sonnet 5.5 piensa por defecto y Opus 4.8 no. Si a Sonnet no se le apaga, el
 * razonamiento se come los 1.024 tokens de Lore y la respuesta sale cortada o
 * vacía. Y `thinking: disabled` en ese modelo es un 400: el job muere y la
 * paciente se queda sin respuesta.
 */
class ModeloDeLoreTest extends TestCase
{
    private function cuerpoEnviado(string $modelo): array
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Hola']],
            'stop_reason' => 'end_turn',
        ], 200)]);

        $ia = new AnthropicService('sk-de-prueba', $modelo);
        $ia->rawChat('sistema', [['role' => 'user', 'content' => 'hola']], [['name' => 'x']]);

        $cuerpo = null;
        Http::assertSent(function (Request $r) use (&$cuerpo) {
            $cuerpo = $r->data();

            return true;
        });

        return $cuerpo;
    }

    public function test_sonnet_5_5_va_sin_razonamiento_y_con_esfuerzo_medio(): void
    {
        $cuerpo = $this->cuerpoEnviado('claude-sonnet-5-5');

        $this->assertSame(['type' => 'between_tools'], $cuerpo['thinking']);
        $this->assertSame('medium', $cuerpo['output_config']['effort']);
    }

    public function test_los_modelos_anteriores_no_reciben_parametros_nuevos(): void
    {
        foreach (['claude-opus-4-8', 'claude-haiku-4-5'] as $modelo) {
            $cuerpo = $this->cuerpoEnviado($modelo);

            $this->assertArrayNotHasKey('thinking', $cuerpo, $modelo);
            $this->assertArrayNotHasKey('output_config', $cuerpo, $modelo);
        }
    }
}
