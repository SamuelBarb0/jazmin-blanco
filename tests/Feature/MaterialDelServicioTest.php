<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Service;
use App\Models\User;
use App\Services\BotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Las fotos y videos del servicio salen aunque el modelo no escriba la
 * etiqueta [[media:...]].
 *
 * Caso de Oscar Muñoz (22/09/2026): llegó del anuncio de implante con
 * «¡Hola! Quiero agendar mi cita de valoración para implante.», Lore le
 * explicó el implante capilar y no le mandó ninguna de sus 6 fotos y videos.
 * La campaña no tenía servicio asociado, así que el único rastro del servicio
 * era el propio texto.
 */
class MaterialDelServicioTest extends TestCase
{
    use RefreshDatabase;

    private User $doctora;

    private Conversation $conversacion;

    private Service $implante;

    private Service $cejas;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.anthropic.key', 'sk-de-prueba');

        $this->doctora = User::factory()->create();

        $this->implante = $this->servicio('Implante Capilar', 'implante-capilar-tecnica-fue', 3);
        $this->cejas = $this->servicio('IMPLANTE CAPILAR DE CEJAS', 'implante-capilar-de-cejas', 2);
        $this->servicio('Valoración Médica', 'valoracion-medica', 0);

        $lead = Lead::create(['user_id' => $this->doctora->id, 'name' => 'Oscar Muñoz', 'phone' => '573125850041']);
        $this->conversacion = Conversation::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'bot_enabled' => true,
        ]);
    }

    private function servicio(string $nombre, string $slug, int $archivos): Service
    {
        $s = Service::create([
            'user_id' => $this->doctora->id, 'name' => $nombre, 'slug' => $slug, 'is_active' => true,
        ]);
        for ($i = 1; $i <= $archivos; $i++) {
            $s->media()->create([
                'user_id' => $this->doctora->id,
                'type' => $i === 1 ? 'video' : 'image',
                'url' => "https://cdn.test/{$slug}-{$i}.jpg",
                'sort_order' => $i,
            ]);
        }

        return $s;
    }

    private function pacienteDice(string $texto): void
    {
        $this->conversacion->messages()->create(['role' => 'user', 'content' => $texto]);
    }

    private function loreDice(string $texto): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => $texto]],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);
    }

    /** @return list<string> */
    private function urlsEnviadas(?Campaign $campana = null): array
    {
        $r = BotService::fromUser($this->doctora)->reply($this->conversacion->fresh(), $campana);

        return array_column($r['media'], 'url');
    }

    public function test_el_caso_de_oscar_manda_el_material_del_implante_capilar(): void
    {
        $this->pacienteDice('¡Hola! Quiero agendar mi cita de valoración para implante.');
        $this->loreDice("¡Hola! Soy Lore, la asistente de la Dra. Jasmin Blanco 😊\n\n¡Qué gusto que te animes a dar el primer paso! El implante capilar es un procedimiento médico que restaura el cabello de forma permanente.");

        $urls = $this->urlsEnviadas();

        $this->assertCount(3, $urls);
        $this->assertStringContainsString('implante-capilar-tecnica-fue', $urls[0]);
    }

    public function test_gana_el_nombre_mas_largo(): void
    {
        $this->pacienteDice('Me interesa el implante capilar de cejas');
        $this->loreDice('Con gusto te cuento del implante capilar de cejas: se trasplantan folículos uno a uno.');

        $urls = $this->urlsEnviadas();

        $this->assertCount(2, $urls);
        $this->assertStringContainsString('implante-capilar-de-cejas', $urls[0]);
    }

    public function test_si_el_modelo_puso_la_etiqueta_no_se_duplica_nada(): void
    {
        $this->pacienteDice('Qué es el implante capilar?');
        $this->loreDice("Te cuento del implante capilar 😊\n[[media:implante-capilar-tecnica-fue]]");

        $this->assertCount(3, $this->urlsEnviadas());
    }

    public function test_no_se_repite_si_ya_se_envio_en_la_conversacion(): void
    {
        $this->conversacion->messages()->create([
            'role' => 'assistant', 'sent_by' => 'bot', 'content' => 'Te comparto unas imágenes',
            'media' => [['type' => 'image', 'url' => 'https://cdn.test/implante-capilar-tecnica-fue-2.jpg', 'caption' => '', 'service' => 'Implante Capilar']],
        ]);
        $this->pacienteDice('¿Y cuánto dura la recuperación del implante capilar?');
        $this->loreDice('La recuperación del implante capilar toma unos días.');

        $this->assertSame([], $this->urlsEnviadas());
    }

    public function test_el_servicio_del_anuncio_manda_aunque_no_se_nombre(): void
    {
        $campana = Campaign::create([
            'user_id' => $this->doctora->id, 'name' => 'AW - Implante', 'service_id' => $this->implante->id, 'platform' => 'meta',
        ]);
        $this->pacienteDice('Hola, quiero información');
        $this->loreDice('¡Hola! Con gusto te ayudo. ¿Qué día te queda bien para la valoración?');

        $this->assertCount(3, $this->urlsEnviadas($campana));
    }

    public function test_sin_servicio_con_material_no_se_manda_nada(): void
    {
        $this->pacienteDice('Hola, ¿qué horarios tienen para la valoración médica?');
        $this->loreDice('Tenemos horarios entre semana para tu valoración médica.');

        $this->assertSame([], $this->urlsEnviadas());
    }

    public function test_no_casa_con_una_palabra_mas_larga(): void
    {
        $this->pacienteDice('hola');
        $this->loreDice('Trabajamos con implantes capilares de última generación.');

        $this->assertSame([], $this->urlsEnviadas());
    }

    public function test_el_mensaje_de_error_no_lleva_material(): void
    {
        $this->pacienteDice('Quiero el implante capilar');
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'nada', 'input' => []]],
            'stop_reason' => 'tool_use',
        ], 200)]);

        $r = BotService::fromUser($this->doctora)->reply($this->conversacion->fresh());

        $this->assertSame([], $r['media']);
    }
}
