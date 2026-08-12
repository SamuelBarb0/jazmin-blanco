<?php

use App\Http\Controllers\Settings\AgendaController;
use App\Http\Controllers\Settings\AiSettingsController;
use App\Http\Controllers\Settings\EquipoController;
use App\Http\Controllers\Settings\GoogleCalendarSettingsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\PaymentSettingsController;
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

    // Equipo de la clínica: quién más puede entrar. Solo la dueña (validado
    // en el controlador, como el resto de ajustes sensibles).
    // Días en que el consultorio no atiende. La fecha va en la URL y no en el
    // cuerpo porque es el identificador del recurso, y así el borrado es
    // idempotente: repetirlo no rompe nada.
    Route::get('settings/agenda', [AgendaController::class, 'index'])->name('agenda.index');
    Route::post('settings/agenda', [AgendaController::class, 'store'])->name('agenda.store');
    Route::delete('settings/agenda/{fecha}', [AgendaController::class, 'destroy'])->name('agenda.destroy');

    Route::get('settings/equipo', [EquipoController::class, 'index'])->name('equipo.index');
    Route::post('settings/equipo', [EquipoController::class, 'store'])->name('equipo.store');
    Route::patch('settings/equipo/{miembro}', [EquipoController::class, 'toggle'])->name('equipo.toggle');
    Route::delete('settings/equipo/{miembro}', [EquipoController::class, 'destroy'])->name('equipo.destroy');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');

    // Integración IA (Anthropic / Claude)
    Route::get('settings/ia', [AiSettingsController::class, 'edit'])->name('ai.edit');
    Route::put('settings/ia', [AiSettingsController::class, 'update'])->name('ai.update');
    Route::delete('settings/ia', [AiSettingsController::class, 'destroy'])->name('ai.destroy');
    Route::post('settings/ia/test', [AiSettingsController::class, 'test'])->name('ai.test');
    Route::put('settings/ia/bot', [AiSettingsController::class, 'updateBot'])->name('ai.bot');
    Route::put('settings/ia/whatsapp', [AiSettingsController::class, 'updateWhatsappBot'])->name('ai.whatsapp');
    Route::put('settings/ia/whatsapp/pruebas', [AiSettingsController::class, 'updateWhatsappTestNumbers'])->name('ai.whatsapp.numbers');

    // Pagos en línea (Mercado Pago)
    Route::get('settings/pagos', [PaymentSettingsController::class, 'edit'])->name('payments.edit');
    Route::put('settings/pagos', [PaymentSettingsController::class, 'update'])->name('payments.update');
    Route::delete('settings/pagos', [PaymentSettingsController::class, 'destroy'])->name('payments.destroy');
    Route::delete('settings/pagos/prueba', [PaymentSettingsController::class, 'destroyTest'])->name('payments.destroyTest');
    Route::post('settings/pagos/test', [PaymentSettingsController::class, 'test'])->name('payments.test');

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
