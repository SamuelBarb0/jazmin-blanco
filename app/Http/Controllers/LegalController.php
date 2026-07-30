<?php

namespace App\Http\Controllers;

use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;

/**
 * Páginas legales públicas (sin sesión).
 *
 * Meta las exige para poder publicar la app de WhatsApp, y de todos modos hacen
 * falta: el consultorio trata datos de salud, que en Colombia son categoría
 * sensible. El contenido se arma con los datos reales de la clínica que están
 * en ajustes, para que no se desincronice del resto del sistema.
 */
class LegalController extends Controller
{
    /**
     * Correo al que llegan las solicitudes de habeas data.
     * Cambiar aquí si el consultorio usa otra dirección.
     */
    private const CORREO_CONTACTO = 'medijazz26@hotmail.com';

    /** Número público de atención del consultorio. */
    private const TELEFONO_CONTACTO = '+57 311 8823955';

    /** Fecha que se muestra como última actualización del documento. */
    private const ACTUALIZADO = '2026-07-29';

    public function privacidad(): View
    {
        return view('legal.privacidad', $this->datos());
    }

    public function terminos(): View
    {
        return view('legal.terminos', $this->datos());
    }

    public function eliminacion(): View
    {
        return view('legal.eliminacion-datos', $this->datos());
    }

    /**
     * Datos compartidos por las tres páginas.
     *
     * `empresa` se pasa desde aquí y no desde el layout a propósito: Blade evalúa
     * el contenido de las secciones ANTES de renderizar el layout, así que una
     * variable definida allí no existiría todavía dentro de @section.
     *
     * @return array<string,mixed>
     */
    private function datos(): array
    {
        return [
            'empresa' => Settings::botConfig(),
            'correo' => self::CORREO_CONTACTO,
            'telefono' => self::TELEFONO_CONTACTO,
            'actualizado' => Carbon::parse(self::ACTUALIZADO)->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
        ];
    }
}
