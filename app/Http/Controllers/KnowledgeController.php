<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeController extends Controller
{
    public const CATEGORIES = ['faq', 'valoracion', 'contraindicacion', 'diferenciador', 'politica', 'ubicacion', 'pago', 'general'];

    public function index(Request $request): Response
    {
        return Inertia::render('knowledge/index', [
            'entries' => $request->user()->knowledgeEntries()->orderBy('sort_order')->orderBy('id')->get(),
            'categories' => self::CATEGORIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->knowledgeEntries()->create($this->validateData($request));

        return back()->with('success', 'Entrada agregada al conocimiento del bot.');
    }

    public function update(Request $request, KnowledgeEntry $knowledge): RedirectResponse
    {
        $this->authorizeEntry($request, $knowledge);
        $knowledge->update($this->validateData($request));

        return back()->with('success', 'Entrada actualizada.');
    }

    public function destroy(Request $request, KnowledgeEntry $knowledge): RedirectResponse
    {
        $this->authorizeEntry($request, $knowledge);
        $knowledge->delete();

        return back()->with('success', 'Entrada eliminada.');
    }

    /**
     * @return array<string,mixed>
     */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'category' => ['required', 'string', 'in:' . implode(',', self::CATEGORIES)],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'is_active' => ['boolean'],
        ]);
    }

    private function authorizeEntry(Request $request, KnowledgeEntry $entry): void
    {
        abort_unless($entry->user_id === $request->user()->id, 403);
    }
}
