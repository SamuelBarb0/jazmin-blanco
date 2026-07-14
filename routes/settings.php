<?php

use App\Http\Controllers\Settings\AiSettingsController;
use App\Http\Controllers\Settings\GoogleCalendarSettingsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');

    // Integración IA (Anthropic / Claude)
    Route::get('settings/ia', [AiSettingsController::class, 'edit'])->name('ai.edit');
    Route::put('settings/ia', [AiSettingsController::class, 'update'])->name('ai.update');
    Route::delete('settings/ia', [AiSettingsController::class, 'destroy'])->name('ai.destroy');
    Route::post('settings/ia/test', [AiSettingsController::class, 'test'])->name('ai.test');
    Route::put('settings/ia/bot', [AiSettingsController::class, 'updateBot'])->name('ai.bot');

    // Integración Google Calendar (cuenta de servicio)
    Route::get('settings/calendar', [GoogleCalendarSettingsController::class, 'edit'])->name('calendar.edit');
    Route::put('settings/calendar', [GoogleCalendarSettingsController::class, 'update'])->name('calendar.update');
    Route::delete('settings/calendar', [GoogleCalendarSettingsController::class, 'destroy'])->name('calendar.destroy');
    Route::post('settings/calendar/test', [GoogleCalendarSettingsController::class, 'test'])->name('calendar.test');

    // Conexión "un clic" del calendario del propio usuario (OAuth de Google)
    Route::get('settings/calendar/google/connect', [GoogleCalendarSettingsController::class, 'connect'])->name('calendar.google.connect');
    Route::get('settings/calendar/google/callback', [GoogleCalendarSettingsController::class, 'callback'])->name('calendar.google.callback');
    Route::delete('settings/calendar/google', [GoogleCalendarSettingsController::class, 'disconnectGoogle'])->name('calendar.google.disconnect');
});
