<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\BotController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CampaignMediaController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\ServiceMediaController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', [ServiceController::class, 'dashboard'])->name('dashboard');

    Route::post('services/generate-context', [ServiceController::class, 'generateContext'])
        ->name('services.generate-context');
    Route::resource('services', ServiceController::class)->except('show');

    // Fotos y videos de cada servicio (los que el bot puede enviar)
    Route::post('services/{service}/media', [ServiceMediaController::class, 'store'])->name('services.media.store');
    Route::patch('service-media/{medium}', [ServiceMediaController::class, 'update'])->name('services.media.update');
    Route::delete('service-media/{medium}', [ServiceMediaController::class, 'destroy'])->name('services.media.destroy');

    // CRM — pipeline y leads
    Route::get('pipeline', [PipelineController::class, 'index'])->name('pipeline');
    Route::patch('leads/{lead}/move', [LeadController::class, 'move'])->name('leads.move');
    Route::resource('leads', LeadController::class)->except('show');

    // Agenda — citas sincronizadas con Google Calendar
    Route::resource('appointments', AppointmentController::class)->only(['index', 'store', 'update', 'destroy']);

    // Campañas de Meta (contexto de origen para el bot)
    Route::post('campaigns/import', [CampaignController::class, 'import'])->name('campaigns.import');
    Route::resource('campaigns', CampaignController::class)->only(['index', 'store', 'update', 'destroy']);

    // Fotos y videos de cada campaña (el creativo del anuncio que el bot puede enviar)
    Route::post('campaigns/{campaign}/media', [CampaignMediaController::class, 'store'])->name('campaigns.media.store');
    Route::patch('campaign-media/{medium}', [CampaignMediaController::class, 'update'])->name('campaigns.media.update');
    Route::delete('campaign-media/{medium}', [CampaignMediaController::class, 'destroy'])->name('campaigns.media.destroy');

    // Bot — base de conocimiento y asistente
    Route::resource('knowledge', KnowledgeController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('asistente', [BotController::class, 'playground'])->name('bot.playground');
    Route::post('asistente/chat', [BotController::class, 'chat'])->name('bot.chat');
    Route::post('asistente/reset', [BotController::class, 'reset'])->name('bot.reset');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
