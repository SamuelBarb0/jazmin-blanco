<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PipelineController extends Controller
{
    public function index(Request $request): Response
    {
        $stages = $request->user()->stages()
            ->orderBy('position')
            ->with(['leads' => fn ($q) => $q->with('tags')->orderBy('position')])
            ->get();

        return Inertia::render('pipeline/board', [
            'stages' => $stages,
        ]);
    }
}
