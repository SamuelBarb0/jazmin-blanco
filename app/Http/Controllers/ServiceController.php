<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Services\AnthropicService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function dashboard(Request $request): Response
    {
        $user = $request->user();
        $leads = $user->leads();

        $stages = $user->stages()->orderBy('position')->withCount('leads')->get();
        $totalLeads = (clone $leads)->count();

        $channelCounts = (clone $leads)->selectRaw('channel, count(*) as c')->groupBy('channel')->pluck('c', 'channel');
        $channels = collect(['whatsapp', 'instagram', 'meta_ads'])->map(function ($key) use ($channelCounts, $totalLeads) {
            $c = (int) ($channelCounts[$key] ?? 0);

            return ['key' => $key, 'count' => $c, 'pct' => $totalLeads ? (int) round($c * 100 / $totalLeads) : 0];
        });

        return Inertia::render('dashboard', [
            'metrics' => [
                'leads' => $totalLeads,
                'leadsMonth' => (clone $leads)->where('created_at', '>=', now()->startOfMonth())->count(),
                'agendadas' => (int) ($stages->firstWhere('slug', 'agendado')?->leads_count ?? 0),
                'cerradas' => (int) $stages->where('is_won', true)->sum('leads_count'),
                'wonValue' => (float) (clone $leads)->whereIn('stage_id', $stages->where('is_won', true)->pluck('id'))->sum('value'),
            ],
            'pipeline' => $stages->map(fn ($s) => ['name' => $s->name, 'count' => $s->leads_count, 'color' => $s->color])->values(),
            'channels' => $channels->values(),
            'servicesStats' => [
                'total' => $user->services()->count(),
                'withAi' => $user->services()->whereNotNull('ai_context')->count(),
            ],
            'recentServices' => $user->services()->latest()->take(5)->get(),
            'aiConfigured' => AnthropicService::fromConfig()->isConfigured(),
        ]);
    }

    public function index(Request $request): Response
    {
        $services = $request->user()->services()
            ->orderBy('sort_order')
            ->latest()
            ->get();

        return Inertia::render('services/index', [
            'services' => $services,
            'aiConfigured' => AnthropicService::fromConfig()->isConfigured(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('services/create', [
            'aiConfigured' => AnthropicService::fromConfig()->isConfigured(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $request->user()->services()->create($data);

        return redirect()->route('services.index')
            ->with('success', 'Servicio creado correctamente.');
    }

    public function edit(Request $request, Service $service): Response
    {
        $this->authorizeService($request, $service);

        return Inertia::render('services/edit', [
            'service' => $service,
            'media' => $service->media()->get(),
            'aiConfigured' => AnthropicService::fromConfig()->isConfigured(),
        ]);
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $this->authorizeService($request, $service);

        $data = $this->validateData($request);

        $service->update($data);

        return redirect()->route('services.index')
            ->with('success', 'Servicio actualizado correctamente.');
    }

    public function destroy(Request $request, Service $service): RedirectResponse
    {
        $this->authorizeService($request, $service);

        $service->delete();

        return redirect()->route('services.index')
            ->with('success', 'Servicio eliminado.');
    }

    /**
     * Genera el contexto del servicio con la ayuda de Anthropic (Claude).
     */
    public function generateContext(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $context = AnthropicService::fromConfig()->generateServiceContext($data);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'ai' => $e->getMessage(),
            ]);
        }

        return back()->with('generatedContext', $context);
    }

    /**
     * @return array<string,mixed>
     */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'ai_context' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function authorizeService(Request $request, Service $service): void
    {
        abort_unless($service->user_id === $request->user()->id, 403);
    }
}
