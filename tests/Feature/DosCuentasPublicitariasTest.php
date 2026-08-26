<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MetaAdsService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Importar campañas de MÁS DE UNA cuenta publicitaria.
 *
 * La clínica tiene dos: la vieja (`act_441443520788559`), que sostiene toda la
 * pauta que hoy produce pacientes —23 campañas, 214 leads solo en la que está
 * activa—, y la nueva del portfolio propio (`act_1447556584094726`), todavía
 * vacía. Con una sola cuenta configurada, apuntar a la nueva escondía el
 * histórico entero a cambio de nada.
 *
 * Ojo con el alcance: esto es SOLO el botón «Importar campañas». La atribución
 * de la paciente que llega por un anuncio (`resolveAdCampaign`) pregunta por el
 * ID DEL ANUNCIO y nunca mira la cuenta, así que esa ya funcionaba con las dos.
 */
class DosCuentasPublicitariasTest extends TestCase
{
    use RefreshDatabase;

    private User $doctora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctora = User::factory()->create();
        config()->set('services.meta.ads_token', 'token-de-prueba');
    }

    /** @param  array<int,array<string,mixed>>  $campanas */
    private function respuesta(array $campanas): PromiseInterface
    {
        return Http::response(['data' => $campanas], 200);
    }

    public function test_una_sola_cuenta_sigue_funcionando_igual(): void
    {
        config()->set('services.meta.ad_account_id', '441443520788559');

        $this->assertSame(['act_441443520788559'], MetaAdsService::fromConfig()->adAccounts());
    }

    /** Se acepta con y sin `act_`, con comas, con espacios y con duplicados. */
    public function test_acepta_varias_cuentas_escritas_de_cualquier_forma(): void
    {
        config()->set('services.meta.ad_account_id', 'act_441443520788559, 1447556584094726');
        $this->assertSame(
            ['act_441443520788559', 'act_1447556584094726'],
            MetaAdsService::fromConfig()->adAccounts()
        );

        config()->set('services.meta.ad_account_id', '441443520788559;441443520788559');
        $this->assertSame(['act_441443520788559'], MetaAdsService::fromConfig()->adAccounts());

        config()->set('services.meta.ad_account_id', '   ');
        $this->assertSame([], MetaAdsService::fromConfig()->adAccounts());
        $this->assertFalse(MetaAdsService::fromConfig()->isConfigured());
    }

    public function test_importa_las_campanas_de_las_dos_cuentas(): void
    {
        config()->set('services.meta.ad_account_id', '441443520788559,1447556584094726');

        Http::fake([
            '*act_441443520788559/campaigns*' => $this->respuesta([
                ['id' => '120251545626100596', 'name' => 'VIDEOS METABOLICO -k- linea 317 2.0', 'effective_status' => 'ACTIVE'],
                ['id' => '120251670728320596', 'name' => 'Remarketing Reset 11/08/2026', 'effective_status' => 'PAUSED'],
            ]),
            '*act_1447556584094726/campaigns*' => $this->respuesta([
                ['id' => '120252000000000001', 'name' => 'Capilar pagina nueva', 'effective_status' => 'ACTIVE'],
            ]),
        ]);

        $this->actingAs($this->doctora)
            ->post(route('campaigns.import'))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, '3 campañas')
                && str_contains($m, '2 cuentas publicitarias'));

        $this->assertDatabaseHas('campaigns', ['meta_campaign_id' => '120251545626100596']);
        $this->assertDatabaseHas('campaigns', ['meta_campaign_id' => '120252000000000001']);
        $this->assertSame(3, $this->doctora->campaigns()->count());
    }

    /**
     * Lo que de verdad importa el día que se añada la cuenta nueva: que si a esa
     * todavía no le pusieron el permiso, la vieja se siga importando igual.
     */
    public function test_si_una_cuenta_falla_la_otra_se_importa_igual(): void
    {
        config()->set('services.meta.ad_account_id', '441443520788559,1447556584094726');

        Http::fake([
            '*act_441443520788559/campaigns*' => $this->respuesta([
                ['id' => '120251545626100596', 'name' => 'VIDEOS METABOLICO -k- linea 317 2.0', 'effective_status' => 'ACTIVE'],
            ]),
            '*act_1447556584094726/campaigns*' => Http::response([
                'error' => ['message' => '(#200) Ad account owner has NOT grant ads_read permission.'],
            ], 403),
        ]);

        $this->actingAs($this->doctora)
            ->post(route('campaigns.import'))
            ->assertRedirect()
            // Y se dice cuál falló: si no, «importé y no salió la nueva» no
            // tiene explicación en ninguna pantalla.
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 campañas')
                && str_contains($m, 'act_1447556584094726')
                && str_contains($m, 'ads_read'));

        $this->assertSame(1, $this->doctora->campaigns()->count());
    }

    /** Que fallen las dos sí es un error: no hay nada que importar. */
    public function test_si_fallan_todas_se_avisa_como_error(): void
    {
        config()->set('services.meta.ad_account_id', '441443520788559,1447556584094726');

        Http::fake(['*' => Http::response(['error' => ['message' => 'Token caducado']], 401)]);

        $this->actingAs($this->doctora)
            ->post(route('campaigns.import'))
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Token caducado'));

        $this->assertSame(0, $this->doctora->campaigns()->count());
    }

    public function test_sin_campanas_no_se_rompe_nada(): void
    {
        config()->set('services.meta.ad_account_id', '441443520788559,1447556584094726');

        Http::fake(['*' => $this->respuesta([])]);

        $this->actingAs($this->doctora)
            ->post(route('campaigns.import'))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, '0 campañas'));
    }

    /**
     * La atribución no depende de la cuenta y no debe empezar a depender de
     * ella: resuelve por ID de anuncio. Esta prueba es la que impide que un
     * refactor futuro le meta la cuenta publicitaria por delante.
     */
    public function test_la_atribucion_resuelve_por_anuncio_sin_mirar_la_cuenta(): void
    {
        config()->set('services.meta.ad_account_id', '441443520788559,1447556584094726');

        Http::fake([
            'graph.facebook.com/*/120251545626050596*' => Http::response([
                'campaign' => ['id' => '120251545626100596', 'name' => 'VIDEOS METABOLICO -k- linea 317 2.0'],
            ], 200),
        ]);

        $resuelta = MetaAdsService::fromConfig()->resolveAdCampaign('120251545626050596');

        $this->assertSame('120251545626100596', $resuelta['id']);

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'act_'));
    }

    public function test_sin_cuentas_configuradas_la_pantalla_lo_dice(): void
    {
        config()->set('services.meta.ad_account_id', null);

        $this->actingAs($this->doctora)
            ->post(route('campaigns.import'))
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'META_AD_ACCOUNT_ID'));
    }

    public function test_fetch_campaigns_sin_configurar_lanza(): void
    {
        config()->set('services.meta.ads_token', null);

        $this->expectException(RuntimeException::class);

        MetaAdsService::fromConfig()->fetchCampaigns();
    }
}
