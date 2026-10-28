<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BotService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editor del system prompt de Lore desde el panel (28/10/2026).
 *
 * La doctora puede reemplazar la plantilla del prompt. El código la rellena con
 * los datos vivos vía etiquetas {{...}}, así que editar el texto NO congela el
 * precio ni la base de conocimiento. Solo la dueña puede tocarlo.
 */
class InstruccionesDeLoreTest extends TestCase
{
    use RefreshDatabase;

    private function duena(): User
    {
        return User::factory()->create();
    }

    private function empleada(User $duena): User
    {
        // Crear con cuenta_id ya puesto evita que booted() la marque dueña.
        return User::factory()->create(['cuenta_id' => $duena->id]);
    }

    public function test_la_plantilla_por_defecto_trae_las_etiquetas(): void
    {
        $plantilla = BotService::defaultPromptTemplate();

        $this->assertStringContainsString('{{base_conocimiento}}', $plantilla);
        $this->assertStringContainsString('{{valor_valoracion}}', $plantilla);
        $this->assertStringContainsString('{{quien_es}}', $plantilla);
    }

    public function test_sin_override_el_prompt_se_rellena_entero(): void
    {
        $prompt = BotService::fromUser($this->duena())->systemPrompt();

        // Ninguna etiqueta queda sin reemplazar y no sobrevive interpolación PHP.
        $this->assertStringNotContainsString('{{', $prompt);
        $this->assertStringNotContainsString('{$', $prompt);

        // Las barandas y el fix de las correcciones siguen en el texto.
        $this->assertStringContainsString('NUNCA afirmes ser humana', $prompt);
        $this->assertStringContainsString('No la presentes como una corrección', $prompt);

        // El valor de la valoración entró de verdad (token reemplazado por $...).
        $this->assertStringContainsString('consulta de valoración es $', $prompt);
    }

    public function test_el_override_reemplaza_la_plantilla_pero_rellena_las_etiquetas(): void
    {
        Settings::setBotPromptOverride('PRUEBA DE LA DOCTORA. La valoración vale {{valor_valoracion}} y punto final.');

        $prompt = BotService::fromUser($this->duena())->systemPrompt();

        $this->assertStringContainsString('PRUEBA DE LA DOCTORA', $prompt);
        $this->assertStringContainsString('vale $', $prompt);           // etiqueta rellenada
        $this->assertStringNotContainsString('{{valor_valoracion}}', $prompt);
        // Ya no se usa la plantilla por defecto.
        $this->assertStringNotContainsString('NUNCA afirmes ser humana', $prompt);
    }

    public function test_la_duena_ve_y_guarda_y_restaura(): void
    {
        $duena = $this->duena();

        $this->actingAs($duena)->get(route('prompt.edit'))->assertOk();

        $this->actingAs($duena)
            ->put(route('prompt.update'), ['prompt' => 'Hola {{doctora}}'])
            ->assertRedirect();
        $this->assertSame('Hola {{doctora}}', Settings::botPromptOverride());

        $this->actingAs($duena)->delete(route('prompt.reset'))->assertRedirect();
        $this->assertNull(Settings::botPromptOverride());
    }

    public function test_el_equipo_no_puede_tocar_las_instrucciones(): void
    {
        $duena = $this->duena();
        $empleada = $this->empleada($duena);

        $this->actingAs($empleada)->get(route('prompt.edit'))->assertForbidden();
        $this->actingAs($empleada)->put(route('prompt.update'), ['prompt' => 'x'])->assertForbidden();
        $this->actingAs($empleada)->delete(route('prompt.reset'))->assertForbidden();
    }
}
