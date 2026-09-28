<?php

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La asistente se presenta SIEMPRE igual (etapa 4 del ajuste).
 *
 * Antes se describía de tres formas distintas según dónde: «la asistente de la
 * Dra. Jasmin Blanco» al presentarse, «asistente virtual de Consultorio Dra.
 * Jasmin Blanco» en el prompt, y —la peor— «la asistente virtual del
 * consultorio», sin nombrar a la doctora, justo al responder si es una persona
 * real. Que es el momento en que la paciente más atención le presta.
 */
class IdentidadDelAsistenteTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_nombre_de_la_doctora_es_un_ajuste_con_respaldo()
    {
        $this->assertSame('Dra. Jasmin Blanco', Settings::botConfig()['doctor_name']);

        Settings::setBotConfig(['doctor_name' => 'Dra. Otra Persona']);

        $this->assertSame('Dra. Otra Persona', Settings::botConfig()['doctor_name']);
    }

    public function test_se_presenta_siempre_como_asistente_de_la_doctora()
    {
        Settings::setBotConfig(['doctor_name' => 'Dra. Jasmin Blanco', 'bot_name' => 'Lore']);

        $prompt = $this->promptDelBot();

        // La fórmula única, y en particular en la respuesta a «¿eres humana?».
        $this->assertStringContainsString('Lore, la asistente de Dra. Jasmin Blanco', $prompt);

        // Lo que se quitó: describirse por el consultorio en vez de por la doctora.
        $this->assertStringNotContainsString('la asistente virtual del consultorio;', $prompt);
    }

    public function test_cambiar_el_nombre_de_la_doctora_cambia_toda_la_identidad()
    {
        // Si con otro nombre siguiera apareciendo el viejo, es que quedó escrito
        // a mano en algún sitio — que es exactamente el fallo que se corrigió.
        Settings::setBotConfig(['doctor_name' => 'Dra. Prueba Uno', 'bot_name' => 'Ada']);

        $prompt = $this->promptDelBot();

        $this->assertStringContainsString('Ada, la asistente de Dra. Prueba Uno', $prompt);

        // El nombre de la clínica es un ajuste aparte y puede contener el de la
        // doctora legítimamente, así que lo que se comprueba es que no quede
        // ninguna mención suelta FUERA de él.
        $sinClinica = str_replace(Settings::botConfig()['clinic_name'], '', $prompt);
        $this->assertStringNotContainsString('Jasmin Blanco', $sinClinica);
    }

    /**
     * El system prompt tal como lo arma el bot, sin salir a la red.
     *
     * `systemPrompt()` es privado porque nadie fuera del servicio debería
     * armarlo; aquí se alcanza por reflexión para poder afirmar sobre el texto
     * real y no sobre una copia que se quedaría vieja al primer cambio.
     */
    private function promptDelBot(): string
    {
        $usuario = \App\Models\User::factory()->create();
        $bot = \App\Services\BotService::fromUser($usuario);

        $metodo = new \ReflectionMethod($bot, 'systemPrompt');
        $metodo->setAccessible(true);

        return $metodo->invoke($bot);
    }
}
