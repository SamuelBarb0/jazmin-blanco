<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServiceMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ServiceMediaController extends Controller
{
    /**
     * Sube un archivo (foto/video) o registra una URL externa para el servicio.
     */
    public function store(Request $request, Service $service): RedirectResponse
    {
        $this->authorizeService($request, $service);

        $data = $request->validate([
            'type' => ['required', Rule::in(['image', 'video'])],
            'caption' => ['nullable', 'string', 'max:500'],
            'file' => ['nullable', 'required_without:url', 'file', 'max:51200', $this->mimeRule($request)],
            'url' => ['nullable', 'required_without:file', 'url', 'max:2048'],
        ]);

        $payload = [
            'user_id' => $service->user_id,
            'type' => $data['type'],
            'caption' => $data['caption'] ?? null,
            'sort_order' => (int) ($service->media()->max('sort_order') + 1),
        ];

        if ($request->hasFile('file')) {
            $payload['path'] = $request->file('file')->store("service-media/{$service->id}", 'public');
        } else {
            $payload['url'] = $data['url'];
        }

        $service->media()->create($payload);

        return back()->with('success', 'Material agregado al servicio.');
    }

    public function update(Request $request, ServiceMedia $medium): RedirectResponse
    {
        $this->authorizeMedia($request, $medium);

        $data = $request->validate([
            'caption' => ['nullable', 'string', 'max:500'],
        ]);

        $medium->update(['caption' => $data['caption'] ?? null]);

        return back()->with('success', 'Descripción actualizada.');
    }

    public function destroy(Request $request, ServiceMedia $medium): RedirectResponse
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

    private function authorizeService(Request $request, Service $service): void
    {
        abort_unless($service->user_id === $request->user()->id, 403);
    }

    private function authorizeMedia(Request $request, ServiceMedia $medium): void
    {
        abort_unless($medium->user_id === $request->user()->id, 403);
    }
}
