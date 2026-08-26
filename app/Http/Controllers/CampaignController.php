<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\PaymentLink;
use App\Services\MetaAdsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class CampaignController extends Controller
{
    public function index(Request $request): Response
    {
        // Filtro por el estado real que trae Meta (effective_status). Vacío = todas.
        $status = $request->string('status')->upper()->trim()->value() ?: null;

        $campaigns = $request->user()->campaigns()
            ->with(['service:id,name', 'media'])
            ->when($status, fn ($q) => $q->where('meta_status', $status))
            ->orderByDesc('created_at')
            ->get();

        // Conteo por estado (para los chips del filtro) sobre TODAS las campañas.
        $statusCounts = $request->user()->campaigns()
            ->whereNotNull('meta_status')
            ->selectRaw('meta_status, count(*) as total')
            ->groupBy('meta_status')
            ->orderByDesc('total')
            ->pluck('total', 'meta_status');

        return Inertia::render('campaigns/index', [
            'campaigns' => $campaigns,
            'services' => $request->user()->services()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'metaAdsConfigured' => MetaAdsService::fromConfig()->isConfigured(),
            'filters' => ['status' => $status],
            'statusCounts' => $statusCounts,
            'total' => $request->user()->campaigns()->count(),
            'results' => $this->results($request),
        ]);
    }

    /**
     * Qué trajo cada campaña: pacientes, citas y valoraciones pagadas.
     *
     * Es lo que responde la única pregunta que importa de un anuncio —si se
     * paga solo—, y el dato ya estaba: `leads.campaign_id` guarda la PRIMERA
     * campaña que trajo a cada paciente y no se sobrescribe, así que sobrevive
     * a que se borren las conversaciones.
     *
     * Tres consultas agrupadas y no una por campaña: así la pantalla cuesta lo
     * mismo con 20 campañas que con 200. El alias es `conteo` y no `total` a
     * propósito: `total` choca con los accesores de algunos modelos y Eloquent
     * hace ganar al accesor, devolviendo ceros sin avisar.
     *
     * @return array<int,array{leads:int,appointments:int,paid:int}>
     */
    private function results(Request $request): array
    {
        // `cuenta_id` y no `id`: las citas cuelgan de la clínica, así que
        // filtrar por el login de quien entró le daba ceros a todo el equipo
        // salvo a la dueña, y sin ningún aviso de que faltaban filas.
        $userId = $request->user()->cuenta_id;

        $leads = $request->user()->leads()
            ->whereNotNull('campaign_id')
            ->selectRaw('campaign_id, count(*) as conteo')
            ->groupBy('campaign_id')
            ->pluck('conteo', 'campaign_id');

        $citas = Appointment::query()
            ->join('leads', 'leads.id', '=', 'appointments.lead_id')
            ->where('appointments.user_id', $userId)
            ->whereNotNull('leads.campaign_id')
            ->selectRaw('leads.campaign_id as campaign_id, count(*) as conteo')
            ->groupBy('leads.campaign_id')
            ->pluck('conteo', 'campaign_id');

        $pagos = PaymentLink::query()
            ->join('leads', 'leads.id', '=', 'payment_links.lead_id')
            ->where('payment_links.user_id', $userId)
            ->where('payment_links.status', PaymentLink::PAGADO)
            ->whereNotNull('leads.campaign_id')
            ->selectRaw('leads.campaign_id as campaign_id, count(*) as conteo')
            ->groupBy('leads.campaign_id')
            ->pluck('conteo', 'campaign_id');

        return $leads->keys()
            ->merge($citas->keys())
            ->merge($pagos->keys())
            ->unique()
            ->mapWithKeys(fn ($id) => [(int) $id => [
                'leads' => (int) ($leads[$id] ?? 0),
                'appointments' => (int) ($citas[$id] ?? 0),
                'paid' => (int) ($pagos[$id] ?? 0),
            ]])
            ->all();
    }

    /**
     * Importa (sincroniza) las campañas reales desde el Administrador de
     * Anuncios de Meta vía la Marketing API.
     */
    public function import(Request $request): RedirectResponse
    {
        $ads = MetaAdsService::fromConfig();

        if (! $ads->isConfigured()) {
            return back()->with('error', 'Falta conectar Meta Ads: agrega META_ADS_TOKEN y META_AD_ACCOUNT_ID en el .env.');
        }

        try {
            $campaigns = $ads->fetchCampaigns();
        } catch (Throwable $e) {
            return back()->with('error', 'No se pudieron importar las campañas: '.$e->getMessage());
        }

        $imported = 0;
        $created = 0;

        foreach ($campaigns as $c) {
            $campaign = $request->user()->campaigns()->firstOrNew(['meta_campaign_id' => $c['id']]);

            $campaign->name = $c['name'];
            $campaign->platform = 'meta';

            // El estado real de Meta se actualiza en cada sync (cambia con el tiempo).
            $campaign->meta_status = filled($c['status']) ? strtoupper((string) $c['status']) : null;

            // La oferta/servicio que la doctora haya puesto se respeta;
            // solo fijamos "activa" la primera vez (al crearla).
            if (! $campaign->exists) {
                $campaign->is_active = in_array($campaign->meta_status, ['ACTIVE', 'CAMPAIGN_ACTIVE'], true);
                $created++;
            }

            $campaign->save();
            $imported++;
        }

        $cuentas = count($ads->adAccounts());
        $resumen = "Meta Ads sincronizado: {$imported} campañas ({$created} nuevas)"
            .($cuentas > 1 ? " de {$cuentas} cuentas publicitarias." : '.');

        // Una cuenta caída no tumba la importación de la otra, pero tiene que
        // decirse: si no, «importé y no salió la campaña nueva» no tiene
        // explicación en ninguna pantalla y parece que el CRM no la ve.
        if ($avisos = $ads->avisos()) {
            $detalle = collect($avisos)->map(fn ($motivo, $cuenta) => "{$cuenta} ({$motivo})")->implode('; ');

            return back()->with('success', $resumen.' ⚠️ No se pudo leer: '.$detalle);
        }

        return back()->with('success', $resumen);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->campaigns()->create($this->validateData($request));

        return back()->with('success', 'Campaña creada.');
    }

    public function update(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->authorizeCampaign($request, $campaign);

        $campaign->update($this->validateData($request));

        return back()->with('success', 'Campaña actualizada.');
    }

    public function destroy(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->authorizeCampaign($request, $campaign);

        $campaign->delete();

        return back()->with('success', 'Campaña eliminada.');
    }

    /**
     * @return array<string,mixed>
     */
    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'service_id' => ['nullable', 'integer'],
            'meta_campaign_id' => ['nullable', 'string', 'max:255'],
            'platform' => ['required', 'string', 'in:meta,facebook,instagram'],
            'offer' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        // El servicio debe pertenecer a la doctora; si no, se ignora.
        if (! empty($data['service_id']) && ! $request->user()->services()->whereKey($data['service_id'])->exists()) {
            $data['service_id'] = null;
        }

        return $data;
    }

    private function authorizeCampaign(Request $request, Campaign $campaign): void
    {
        abort_unless($request->user()->esDeSuCuenta($campaign), 403);
    }
}
