<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\PaymentLink;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * De qué anuncio viene cada paciente y qué trajo cada campaña.
 *
 * El dato ya se guardaba desde el primer mensaje (`leads.campaign_id`) pero no
 * se pintaba en ninguna pantalla: ni el pipeline, ni el listado de leads, ni la
 * bandeja lo mencionaban, así que la única forma de saberlo era consultar la
 * base a mano.
 */
class AtribucionDeCampanaTest extends TestCase
{
    use RefreshDatabase;

    private User $doctora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctora = User::factory()->create();
    }

    private function campana(string $nombre): Campaign
    {
        return Campaign::create([
            'user_id' => $this->doctora->id,
            'name' => $nombre,
            'platform' => 'meta',
            'is_active' => true,
        ]);
    }

    private function lead(string $nombre, ?Campaign $campana = null): Lead
    {
        return Lead::create([
            'user_id' => $this->doctora->id,
            'campaign_id' => $campana?->id,
            'name' => $nombre,
            'phone' => '5730011122'.random_int(10, 99),
        ]);
    }

    public function test_el_pipeline_manda_la_campana_de_cada_lead(): void
    {
        $campana = $this->campana('VIDEOS METABOLICO -k-');

        // En el tablero los leads cuelgan de una etapa: sin ella la columna
        // sale vacía y no habría tarjeta donde pintar el origen.
        $etapa = Stage::create([
            'user_id' => $this->doctora->id,
            'name' => 'Nuevo',
            'slug' => 'nuevo',
            'color' => 'sky',
            'position' => 0,
        ]);

        $this->lead('Flor Elena', $campana)->forceFill(['stage_id' => $etapa->id])->save();

        $this->actingAs($this->doctora)->get(route('pipeline'))
            ->assertInertia(fn ($page) => $page
                ->where('stages.0.leads.0.campaign.name', 'VIDEOS METABOLICO -k-'));
    }

    public function test_el_listado_de_leads_manda_la_campana(): void
    {
        $campana = $this->campana('Reseteo hormonal');
        $this->lead('Natalia', $campana);

        $this->actingAs($this->doctora)->get(route('leads.index'))
            ->assertInertia(fn ($page) => $page->where('leads.0.campaign.name', 'Reseteo hormonal'));
    }

    public function test_los_resultados_cuentan_pacientes_citas_y_pagos_por_campana(): void
    {
        $campana = $this->campana('VIDEOS METABOLICO -k-');
        $otra = $this->campana('Campaña sin nadie');

        $conCita = $this->lead('Flor Elena', $campana);
        $soloLead = $this->lead('Crespa', $campana);
        $this->lead('Vino sola', null);

        Appointment::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $conCita->id,
            'patient_name' => 'Flor Elena',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(45),
            'status' => 'scheduled',
        ]);

        PaymentLink::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $conCita->id,
            'reference' => 'ref-1',
            'payment_link' => 'pref-1',
            'url' => 'https://pago.test/1',
            'amount' => 75000,
            'status' => PaymentLink::PAGADO,
        ]);

        // Un link sin pagar NO cuenta: el embudo mide plata que entró.
        PaymentLink::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $soloLead->id,
            'reference' => 'ref-2',
            'payment_link' => 'pref-2',
            'url' => 'https://pago.test/2',
            'amount' => 75000,
            'status' => 'PENDING',
        ]);

        $this->actingAs($this->doctora)->get(route('campaigns.index'))
            ->assertInertia(fn ($page) => $page
                ->where("results.{$campana->id}.leads", 2)
                ->where("results.{$campana->id}.appointments", 1)
                ->where("results.{$campana->id}.paid", 1)
                // La campaña que no trajo a nadie no aparece: la pantalla no se
                // llena de ceros con las campañas viejas importadas de Meta.
                ->missing("results.{$otra->id}"));
    }

    /**
     * Los leads de otra doctora no pueden sumar en estos números: el conteo va
     * por `join` a `leads`, y sin filtrar por dueño se colarían.
     */
    public function test_los_resultados_no_mezclan_datos_de_otra_cuenta(): void
    {
        $campana = $this->campana('VIDEOS METABOLICO -k-');
        $mio = $this->lead('Flor Elena', $campana);

        $ajena = User::factory()->create();
        $suyo = Lead::create([
            'user_id' => $ajena->id,
            'campaign_id' => $campana->id,
            'name' => 'Paciente ajena',
            'phone' => '573009998877',
        ]);

        foreach ([$mio, $suyo] as $lead) {
            Appointment::create([
                'user_id' => $lead->user_id,
                'lead_id' => $lead->id,
                'patient_name' => $lead->name,
                'starts_at' => now()->addDay(),
                'ends_at' => now()->addDay()->addMinutes(45),
                'status' => 'scheduled',
            ]);
        }

        $this->actingAs($this->doctora)->get(route('campaigns.index'))
            ->assertInertia(fn ($page) => $page
                ->where("results.{$campana->id}.leads", 1)
                ->where("results.{$campana->id}.appointments", 1));
    }
}
