<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\MediaParaWhatsApp;
use App\Models\Campaign;
use App\Models\CampaignMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CampaignMediaController extends Controller
{
    use MediaParaWhatsApp;

    /**
     * Sube un archivo (foto/video) o registra una URL externa para la campaña.
     */
    public function store(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->authorizeCampaign($request, $campaign);

        $tipo = $request->input('type') === 'video' ? 'video' : 'image';

        $data = $request->validate([
            'type' => ['required', Rule::in(['image', 'video'])],
            'caption' => ['nullable', 'string', 'max:500'],
            'file' => $this->reglasDelArchivo($tipo),
            'url' => ['nullable', 'required_without:file', 'url', 'max:2048'],
        ], [
            'file.max' => $this->mensajeDeTamano($tipo),
        ]);

        $payload = [
            'user_id' => $campaign->user_id,
            'type' => $data['type'],
            'caption' => $data['caption'] ?? null,
            'sort_order' => (int) ($campaign->media()->max('sort_order') + 1),
        ];

        if ($request->hasFile('file')) {
            $payload['path'] = $request->file('file')->store("campaign-media/{$campaign->id}", 'public');
        } else {
            $payload['url'] = $data['url'];
        }

        $medio = $campaign->media()->create($payload);

        // Si se pasa del tope de WhatsApp, la cola lo deja a medida sin que la
        // doctora tenga que hacer nada; ver CompressUploadedVideo.
        $this->optimizarSiHaceFalta($medio);

        return back()->with('success', 'Material agregado a la campaña.');
    }

    public function update(Request $request, CampaignMedia $medium): RedirectResponse
    {
        $this->authorizeMedia($request, $medium);

        $data = $request->validate([
            'caption' => ['nullable', 'string', 'max:500'],
        ]);

        $medium->update(['caption' => $data['caption'] ?? null]);

        return back()->with('success', 'Descripción actualizada.');
    }

    public function destroy(Request $request, CampaignMedia $medium): RedirectResponse
    {
        $this->authorizeMedia($request, $medium);

        if (filled($medium->path)) {
            Storage::disk('public')->delete($medium->path);
        }

        $medium->delete();

        return back()->with('success', 'Material eliminado.');
    }

    private function authorizeCampaign(Request $request, Campaign $campaign): void
    {
        abort_unless($request->user()->esDeSuCuenta($campaign), 403);
    }

    private function authorizeMedia(Request $request, CampaignMedia $medium): void
    {
        abort_unless($request->user()->esDeSuCuenta($medium), 403);
    }
}
