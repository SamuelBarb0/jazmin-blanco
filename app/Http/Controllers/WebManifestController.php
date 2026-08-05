<?php

namespace App\Http\Controllers;

use App\Support\AppShortcuts;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Manifiesto de la app web, generado por página.
 *
 * Antes era un archivo estático con `start_url: /dashboard`, y como iOS abre
 * el acceso directo en el `start_url` del manifiesto —no en la página donde lo
 * creaste—, fijar "Conversaciones" en la pantalla de inicio abría el Centro de
 * comando igual. Ahora cada página enlaza su propio manifiesto y el icono
 * queda apuntando donde debe.
 *
 * Va en `/app.webmanifest` y no en el `/site.webmanifest` de antes **a
 * propósito**: el CDN de Hostinger cachea los estáticos siete días, así que
 * reemplazar aquel archivo por una ruta dinámica habría seguido sirviendo la
 * copia vieja. URL nueva = sin copia cacheada.
 */
class WebManifestController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $inicio = AppShortcuts::inicio($request->query('start'));
        $seccion = AppShortcuts::etiqueta($request->query('start'));

        // `id` distinto por sección: con el mismo id, el sistema considera que
        // los accesos directos son la MISMA app y reutiliza el primero.
        $manifest = [
            'id' => $inicio,
            'name' => 'Aurum · '.$seccion,
            'short_name' => $seccion,
            'description' => 'CRM del consultorio de la Dra. Jasmin Blanco',
            'start_url' => $inicio,
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait-primary',
            'background_color' => '#ffffff',
            'theme_color' => '#0b1220',
            'icons' => [
                ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/logo-aurum-mark.png', 'sizes' => '256x256', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];

        return response(
            json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            200,
            ['Content-Type' => 'application/manifest+json; charset=utf-8']
        );
    }
}
