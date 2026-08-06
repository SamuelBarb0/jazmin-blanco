<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PipelineController extends Controller
{
    public function index(Request $request): Response
    {
        // La campaña viaja con el lead para poder marcar en la tarjeta quién
        // llegó pagando publicidad: el dato ya se guardaba desde el primer
        // mensaje, pero no se pintaba en ninguna pantalla.
        $stages = $request->user()->stages()
            ->orderBy('position')
            ->with(['leads' => fn ($q) => $q->with(['tags', 'campaign:id,name'])->orderBy('position')])
            ->get();

        return Inertia::render('pipeline/board', [
            'stages' => $stages,
        ]);
    }
}
