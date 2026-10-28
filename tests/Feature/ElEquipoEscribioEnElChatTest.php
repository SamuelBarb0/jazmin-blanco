<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Services\BotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Lore tiene que leer lo que el equipo escribió a mano como del equipo.
 *
 * Dos casos del 25/10/2026, los dos reportados por el consultorio como
 * «no tiene hilo» y «me hace quedar mal»:
 *
 *  - Yeimy Torres: el equipo le respondió firmando como la doctora. Ese mensaje
 *    se guarda como `assistant`, así que Lore lo leyó como suyo y, como tiene
 *    prohibido hacerse pasar por la doctora, le escribió a la paciente que ese
 *    mensaje «no lo escribí yo».
 *
 *  - Lucy Salazar: el equipo le confirmó la cita a mano, ella dijo «Gracias» y
 *    Lore retomó el chat. Intentó agendar de nuevo, la guarda de citas cortó el
 *    mensaje y salió «déjame confirmarlo con el equipo»… con las cuentas
 *    bancarias pegadas debajo.
 */
class ElEquipoEscribioEnElChatTest extends TestCase
{
    use RefreshDatabase;

    private User $doctora;

    private Conversation $conversacion;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.anthropic.key', 'sk-de-prueba');

        $this->doctora = User::factory()->create();

        $lead = Lead::create([
            'user_id' => $this->doctora->id,
            'name' => 'Lucy Salazar',
            'phone' => '573002263341',
        ]);

        $this->conversacion = Conversation::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'bot_enabled' => true,
        ]);

        $this->conversacion->messages()->create(['role' => 'user', 'content' => 'Ya pagué la consulta']);
        $this->conversacion->messages()->create(['role' => 'assistant', 'sent_by' => 'bot', 'content' => 'Gracias, ¿me envías el comprobante?']);
        $this->conversacion->messages()->create([
            'role' => 'assistant',
            'sent_by' => 'human',
            'content' => 'Hola Lucy, tu cita quedó agendada para el martes a las 10 a. m. Dra. Jasmin Blanco',
        ]);
        $this->conversacion->messages()->create(['role' => 'user', 'content' => 'Gracias']);
    }

    private function loreDice(string $texto): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => $texto]],
                'stop_reason' => 'end_turn',
            ], 200),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200),
        ]);
    }

    private function contenidos(Request $request, string $rol): array
    {
        return collect($request['messages'])
            ->where('role', $rol)
            ->pluck('content')
            ->filter(fn ($c) => is_string($c))
            ->values()
            ->all();
    }

    public function test_el_modelo_ve_marcado_lo_que_escribio_el_equipo(): void
    {
        $this->loreDice('¡Con gusto, Lucy! 💙');

        BotService::fromUser($this->doctora)->reply($this->conversacion->fresh());

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'anthropic')) {
                return false;
            }

            $deLore = $this->contenidos($request, 'assistant');

            return $deLore[0] === 'Gracias, ¿me envías el comprobante?'
                && str_starts_with($deLore[1], BotService::MARCA_EQUIPO)
                && str_contains($deLore[1], 'tu cita quedó agendada');
        });
    }

    public function test_el_prompt_le_prohibe_desmentir_al_equipo(): void
    {
        $prompt = BotService::fromUser($this->doctora)->systemPrompt();

        $this->assertStringContainsString(BotService::MARCA_EQUIPO, $prompt);
        $this->assertStringContainsString('NUNCA los desmientas', $prompt);
    }

    /**
     * 28/10/2026: la doctora reportó que, tras ajustar un precio y una promoción,
     * Lore se «corregía» en voz alta ante la paciente («tengo que corregir algunos
     * datos que te di antes», «no tengo una promoción de $1.500.000»). El prompt
     * debe prohibir anunciar correcciones y nombrar promos/anticipos ya muertos.
     */
    public function test_el_prompt_prohibe_anunciar_correcciones(): void
    {
        $prompt = BotService::fromUser($this->doctora)->systemPrompt();

        $this->assertStringContainsString('Si la información cambió desde antes en el chat', $prompt);
        $this->assertStringContainsString('No la presentes como una corrección', $prompt);
        $this->assertStringContainsString('que ya no existe para aclarar que ya no lo tienes', $prompt);
    }

    public function test_si_imita_la_marca_no_le_llega_a_la_paciente(): void
    {
        $this->loreDice(BotService::MARCA_EQUIPO."\n¡Con gusto, Lucy! 💙");

        $texto = BotService::fromUser($this->doctora)->reply($this->conversacion->fresh())['text'];

        $this->assertSame('¡Con gusto, Lucy! 💙', $texto);
    }

    /** El caso de Lucy: el equipo ya confirmó, así que la guarda escala sin decir nada. */
    public function test_tras_la_atencion_del_equipo_la_guarda_escala_en_silencio(): void
    {
        $this->loreDice('¡Listo, Lucy! Tu cita quedó agendada para el martes a las 10 a. m.');

        $bot = BotService::fromUser($this->doctora);
        (new ReflectionProperty($bot, 'datosPagoPorAnexar'))->setValue($bot, 'DAVIVIENDA Ahorros: 0550108900811119');

        $resultado = $bot->reply($this->conversacion->fresh());

        $this->assertSame('', $resultado['text']);
        $this->assertSame([], $resultado['media']);
        $this->assertNotNull($this->conversacion->fresh()->escalated_at);
    }

    /** Sin intervención reciente del equipo sigue saliendo el aviso, pero nunca con cuentas. */
    public function test_el_aviso_de_la_guarda_nunca_lleva_datos_de_pago(): void
    {
        Message::where('sent_by', 'human')->update(['created_at' => now()->subDays(5)]);

        $this->loreDice('¡Listo! Tu cita quedó agendada para el martes a las 10 a. m.');

        $bot = BotService::fromUser($this->doctora);
        (new ReflectionProperty($bot, 'datosPagoPorAnexar'))->setValue($bot, 'DAVIVIENDA Ahorros: 0550108900811119');

        $texto = $bot->reply($this->conversacion->fresh())['text'];

        $this->assertStringContainsString('equipo del consultorio', $texto);
        $this->assertStringNotContainsString('0550108900811119', $texto);
    }
}
