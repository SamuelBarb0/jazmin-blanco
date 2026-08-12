<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * El referral de un anuncio trae el id del ANUNCIO, no el de la campaña.
 *
 * Durante un tiempo, cuando la campaña padre no estaba importada todavía, se
 * guardaba una fila por anuncio. Así, una misma campaña salía varias veces con
 * el nombre repetido, sus conversaciones quedaban repartidas entre las copias,
 * y al importar después entraba OTRA fila —la de verdad— con cero chats. Con
 * eso no hay forma de saber qué campaña funciona.
 */
class CampanaDeAnuncioTest extends TestCase
{
    use RefreshDatabase;

    private User $doctora;

    private const AD_ID = '120251669621520596';

    private const CAMPAIGN_ID = '120251669621510596';

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctora = User::factory()->create();

        config()->set('services.meta.ads_token', 'token-de-prueba');
        config()->set('services.meta.ad_account_id', 'act_123');

        Cache::flush();   // el id de la campaña padre se cachea un día
    }

    /** Meta contesta que ese anuncio pertenece a esa campaña. */
    private function metaResponde(string $nombreCampana = 'Reset Sebas R 10/08/2026'): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'id' => self::AD_ID,
            'campaign' => ['id' => self::CAMPAIGN_ID, 'name' => $nombreCampana],
        ], 200)]);
    }

    private function resolver(array $referral): ?Campaign
    {
        $job = new ProcessWhatsAppMessage(
            from: '573001112233',
            text: 'Hola, quiero más información',
            referral: $referral,
        );

        $metodo = new ReflectionMethod(ProcessWhatsAppMessage::class, 'resolveCampaign');
        $metodo->setAccessible(true);

        return $metodo->invoke($job, $this->doctora);
    }

    private function referral(string $adId = self::AD_ID): array
    {
        return [
            'source_id' => $adId,
            'source_type' => 'ad',
            'headline' => 'REDUCE TU PESO',
            'body' => '¿Sientes que tu metabolismo está trabado y nada de lo que haces funciona?',
        ];
    }

    public function test_se_guarda_la_campana_y_no_el_anuncio(): void
    {
        $this->metaResponde();

        $campana = $this->resolver($this->referral());

        $this->assertSame(self::CAMPAIGN_ID, $campana->meta_campaign_id);
        $this->assertSame('Reset Sebas R 10/08/2026', $campana->name);
        // Y el texto del anuncio queda como punto de partida de la oferta.
        $this->assertStringContainsString('metabolismo', (string) $campana->offer);
    }

    /**
     * El caso que provocaba los duplicados: dos anuncios distintos de la misma
     * campaña tienen que caer en la MISMA fila.
     */
    public function test_dos_anuncios_de_la_misma_campana_no_crean_dos_filas(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'campaign' => ['id' => self::CAMPAIGN_ID, 'name' => 'Reset Sebas R 10/08/2026'],
        ], 200)]);

        $uno = $this->resolver($this->referral('111111111111111'));
        $dos = $this->resolver($this->referral('222222222222222'));

        $this->assertSame($uno->id, $dos->id);
        $this->assertSame(1, Campaign::count());
    }

    /**
     * Y lo que rompía al importar después: la fila que creó el bot tiene que
     * ser la misma que encuentra la importación, no una gemela.
     */
    public function test_la_importacion_posterior_actualiza_esa_misma_fila(): void
    {
        $this->metaResponde();
        $delBot = $this->resolver($this->referral());

        // La importación busca exactamente así.
        $encontrada = $this->doctora->campaigns()
            ->where('meta_campaign_id', self::CAMPAIGN_ID)
            ->first();

        $this->assertNotNull($encontrada);
        $this->assertSame($delBot->id, $encontrada->id);
        $this->assertSame(1, Campaign::count());
    }

    /** Si ya estaba importada, se usa esa y no se toca su nombre ni su oferta. */
    public function test_si_la_campana_ya_existe_se_respeta_lo_que_tenga(): void
    {
        $this->metaResponde();

        $existente = Campaign::create([
            'user_id' => $this->doctora->id,
            'meta_campaign_id' => self::CAMPAIGN_ID,
            'name' => 'El nombre que puso la doctora',
            'offer' => 'La oferta que escribió ella',
            'platform' => 'meta',
            'is_active' => true,
        ]);

        $campana = $this->resolver($this->referral());

        $this->assertSame($existente->id, $campana->id);
        $this->assertSame('El nombre que puso la doctora', $campana->fresh()->name);
        $this->assertSame('La oferta que escribió ella', $campana->fresh()->offer);
    }

    /**
     * Sin poder preguntarle a Meta no hay forma de saber la campaña padre, así
     * que se cae al id del anuncio: es el único identificador estable que hay.
     * Peor que agrupar por campaña, pero mejor que perder la atribución.
     */
    public function test_si_meta_no_contesta_se_guarda_por_anuncio(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([], 500)]);

        $campana = $this->resolver($this->referral());

        $this->assertSame(self::AD_ID, $campana->meta_campaign_id);
    }

    /** Y una caída de red no puede tumbar el job: la paciente espera respuesta. */
    public function test_si_meta_se_cae_no_revienta(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $campana = $this->resolver($this->referral());

        $this->assertSame(self::AD_ID, $campana->meta_campaign_id);
    }

    public function test_sin_referral_no_hay_campana(): void
    {
        $this->assertNull($this->resolver([]));
    }

    // ─────────────── El comando de limpieza ───────────────

    public function test_el_comando_junta_el_anuncio_con_su_campana(): void
    {
        $this->metaResponde();

        // Como quedaron los datos: una fila que es la campaña de verdad…
        $real = Campaign::create([
            'user_id' => $this->doctora->id,
            'meta_campaign_id' => self::CAMPAIGN_ID,
            'name' => 'Reset Sebas R 10/08/2026',
            'platform' => 'meta',
            'is_active' => true,
        ]);

        // …y otra que en realidad es uno de sus anuncios, con los chats.
        $delAnuncio = Campaign::create([
            'user_id' => $this->doctora->id,
            'meta_campaign_id' => self::AD_ID,
            'name' => 'fh',
            'offer' => 'el texto del anuncio',
            'platform' => 'meta',
            'is_active' => true,
        ]);

        $lead = Lead::create([
            'user_id' => $this->doctora->id,
            'campaign_id' => $delAnuncio->id,
            'name' => 'Sandra',
            'phone' => '573108802774',
        ]);
        $chat = Conversation::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $lead->id,
            'campaign_id' => $delAnuncio->id,
            'channel' => 'whatsapp',
            'title' => 'WhatsApp · Sandra',
        ]);

        // Meta dice que el id de la campaña real NO tiene padre (ya es campaña).
        Http::fake([
            'graph.facebook.com/'.self::AD_ID.'*' => Http::response(
                ['campaign' => ['id' => self::CAMPAIGN_ID, 'name' => 'Reset Sebas R 10/08/2026']], 200),
            'graph.facebook.com/*' => Http::response(['id' => self::CAMPAIGN_ID, 'name' => 'Reset Sebas R 10/08/2026'], 200),
        ]);

        $this->artisan('campanas:fusionar-anuncios')->assertSuccessful();

        // Queda una sola campaña, con todo colgando de ella.
        $this->assertSame(1, Campaign::count());
        $this->assertSame($real->id, $chat->fresh()->campaign_id);
        $this->assertSame($real->id, $lead->fresh()->campaign_id);
        // Y la oferta del anuncio se hereda, que la importada venía sin ella.
        $this->assertSame('el texto del anuncio', $real->fresh()->offer);
    }

    public function test_en_seco_no_toca_nada(): void
    {
        Campaign::create([
            'user_id' => $this->doctora->id,
            'meta_campaign_id' => self::AD_ID,
            'name' => 'fh',
            'platform' => 'meta',
            'is_active' => true,
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(
            ['campaign' => ['id' => self::CAMPAIGN_ID, 'name' => 'Reset Sebas R']], 200)]);

        $this->artisan('campanas:fusionar-anuncios', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(self::AD_ID, Campaign::first()->meta_campaign_id);
    }
}
