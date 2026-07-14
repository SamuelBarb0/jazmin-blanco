<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    public function index(Request $request): Response
    {
        $leads = $request->user()->leads()
            ->with(['stage', 'tags'])
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('leads/index', [
            'leads' => $leads,
            'stages' => $request->user()->stages()->orderBy('position')->get(['id', 'name', 'color']),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('leads/create', $this->formData($request));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $tags = $data['tags'] ?? [];
        unset($data['tags']);

        $data['stage_id'] = $this->resolveStageId($request, $data['stage_id'] ?? null);
        $data['position'] = (int) $request->user()->leads()->where('stage_id', $data['stage_id'])->max('position') + 1;

        $lead = $request->user()->leads()->create($data);
        $lead->tags()->sync($this->ownedTagIds($request, $tags));

        return redirect()->route('leads.index')->with('success', 'Lead creado correctamente.');
    }

    public function edit(Request $request, Lead $lead): Response
    {
        $this->authorizeLead($request, $lead);
        $lead->load('tags');

        return Inertia::render('leads/edit', [
            ...$this->formData($request),
            'lead' => $lead,
        ]);
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($request, $lead);

        $data = $this->validateData($request);
        $tags = $data['tags'] ?? [];
        unset($data['tags']);

        $data['stage_id'] = $this->resolveStageId($request, $data['stage_id'] ?? null);

        $lead->update($data);
        $lead->tags()->sync($this->ownedTagIds($request, $tags));

        return redirect()->route('leads.index')->with('success', 'Lead actualizado.');
    }

    public function destroy(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($request, $lead);
        $lead->delete();

        return back()->with('success', 'Lead eliminado.');
    }

    /**
     * Mueve un lead a otra etapa y reordena la columna destino (drag-and-drop).
     */
    public function move(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($request, $lead);

        $data = $request->validate([
            'stage_id' => ['required', 'integer'],
            'ids' => ['array'],
            'ids.*' => ['integer'],
        ]);

        // La etapa destino debe pertenecer a la doctora.
        abort_unless($request->user()->stages()->whereKey($data['stage_id'])->exists(), 403);

        $lead->update(['stage_id' => $data['stage_id']]);

        foreach ($data['ids'] ?? [] as $position => $id) {
            $request->user()->leads()->whereKey($id)->update(['position' => $position]);
        }

        return back(303);
    }

    /**
     * @return array{stages: \Illuminate\Support\Collection, tags: \Illuminate\Support\Collection, services: array<int,string>}
     */
    private function formData(Request $request): array
    {
        return [
            'stages' => $request->user()->stages()->orderBy('position')->get(['id', 'name', 'color']),
            'tags' => $request->user()->tags()->orderBy('name')->get(['id', 'name', 'color']),
            'services' => $request->user()->services()->orderBy('name')->pluck('name'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'channel' => ['required', 'string', 'in:whatsapp,instagram,meta_ads,manual,otro'],
            'source' => ['nullable', 'string', 'max:255'],
            'service_interest' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'stage_id' => ['nullable', 'integer'],
            'tags' => ['array'],
            'tags.*' => ['integer'],
        ]);
    }

    /**
     * @param  array<int,int>  $tagIds
     * @return array<int,int>
     */
    private function ownedTagIds(Request $request, array $tagIds): array
    {
        return $request->user()->tags()->whereKey($tagIds)->pluck('id')->all();
    }

    /**
     * Garantiza que la etapa pertenezca a la doctora; si no, usa la primera.
     */
    private function resolveStageId(Request $request, ?int $stageId): ?int
    {
        if ($stageId && $request->user()->stages()->whereKey($stageId)->exists()) {
            return $stageId;
        }

        return $request->user()->stages()->orderBy('position')->value('id');
    }

    private function authorizeLead(Request $request, Lead $lead): void
    {
        abort_unless($lead->user_id === $request->user()->id, 403);
    }
}
