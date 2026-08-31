<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\MediaParaWhatsApp;
use App\Models\Service;
use App\Models\ServiceMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ServiceMediaController extends Controller
{
    use MediaParaWhatsApp;

    /**
     * Sube un archivo (foto/video) o registra una URL externa para el servicio.
     */
    public function store(Request $request, Service $service): RedirectResponse
    {
        $this->authorizeService($request, $service);

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

        $medio = $service->media()->create($payload);

        // Si se pasa del tope de WhatsApp, la cola lo deja a medida sin que la
        // doctora tenga que hacer nada; ver CompressUploadedVideo.
        $this->optimizarSiHaceFalta($medio);

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

    private function authorizeService(Request $request, Service $service): void
    {
        abort_unless($request->user()->esDeSuCuenta($service), 403);
    }

    private function authorizeMedia(Request $request, ServiceMedia $medium): void
    {
        abort_unless($request->user()->esDeSuCuenta($medium), 403);
    }
}
