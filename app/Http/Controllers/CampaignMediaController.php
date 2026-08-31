<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignMedia;
use App\Services\WhatsAppService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CampaignMediaController extends Controller
{
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
            'file' => ['nullable', 'required_without:url', 'file', $this->sizeRule($tipo), $this->mimeRule($request)],
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

        $campaign->media()->create($payload);

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

    /**
     * Limita las extensiones según el tipo declarado.
     */
    private function mimeRule(Request $request): string
    {
        return $request->input('type') === 'video'
            ? 'mimes:mp4,webm,mov,ogg'
            : 'mimes:jpg,jpeg,png,webp,gif';
    }

    /**
     * Tope de peso según el tipo, en kilobytes.
     *
     * Ver la nota de `ServiceMediaController::sizeRule()`: el `max:51200` (50
     * MB) que había aquí acepta archivos que WhatsApp rechaza después, cuando
     * ya es tarde para avisar a quien los subió.
     */
    private function sizeRule(string $tipo): string
    {
        return 'max:'.intdiv(WhatsAppService::limiteBytes($tipo), 1024);
    }

    private function mensajeDeTamano(string $tipo): string
    {
        return sprintf(
            'WhatsApp no acepta %s de más de %d MB, así que este archivo nunca le llegaría a la paciente. Súbelo comprimido.',
            $tipo === 'video' ? 'videos' : 'imágenes',
            WhatsAppService::limiteMb($tipo),
        );
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
