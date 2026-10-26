<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BotService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 26/10/2026: la doctora pidió que la consulta quede en $150.000 y quitar lo
 * de los abonos. Lore cotizaba el valor que la doctora había escrito en la
 * base de conocimiento ($75.000) y una regla fija le hacía decir que se
 * abonaba al procedimiento.
 */
class ValorDeLaValoracionTest extends TestCase
{
    use RefreshDatabase;

    public function test_lore_cotiza_el_valor_del_ajuste_de_pagos_y_no_habla_de_abonos(): void
    {
        config()->set('services.anthropic.key', 'sk-de-prueba');
        Settings::setValoracionAmount(150000);

        $prompt = BotService::fromUser(User::factory()->create())->systemPrompt();

        $this->assertStringContainsString('es $150.000', $prompt);
        $this->assertStringNotContainsString('se le ABONA', $prompt);
        $this->assertStringContainsString('NO digas que la valoración se abona', $prompt);
    }

    /**
     * 26/10/2026: a pacientes de Neiva, Barranquilla o Bucaramanga Lore les
     * decía que dejaba la consulta con el equipo. La doctora quiere que se les
     * diga de una vez que solo atiende en Bogotá.
     */
    public function test_a_pacientes_de_otras_ciudades_no_las_escala(): void
    {
        config()->set('services.anthropic.key', 'sk-de-prueba');

        $prompt = BotService::fromUser(User::factory()->create())->systemPrompt();

        $this->assertStringContainsString('es la ÚNICA sede', $prompt);
        $this->assertStringContainsString('NO escales con escalar_a_humano ni le digas que dejaste su consulta con el equipo', $prompt);
    }
}
