<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServiceMedia;
use App\Services\WhatsAppService;
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

    /**
     * Tope de peso según el tipo, en kilobytes.
     *
     * Hasta el 31-ago-2026 esto era un `max:51200` (50 MB) para todo, muy por
     * encima de lo que acepta WhatsApp. Un video de 35 MB entró al panel el
     * 20-ago y el bot lo intentó enviar durante once días, fallando siempre con
     * el acuse `131053` mientras en la bandeja figuraba como enviado. Aceptar
     * aquí lo que allá se rechaza es prometer algo que no se puede cumplir.
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

    private function authorizeService(Request $request, Service $service): void
    {
        abort_unless($request->user()->esDeSuCuenta($service), 403);
    }

    private function authorizeMedia(Request $request, ServiceMedia $medium): void
    {
        abort_unless($request->user()->esDeSuCuenta($medium), 403);
    }
}
