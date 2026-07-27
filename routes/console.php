<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Recordatorios de cita por WhatsApp (2 días antes y 24 horas antes).
 *
 * Cada hora basta: el comando calcula las ventanas contra la hora actual, así
 * que si una corrida falla la siguiente recupera lo pendiente. La franja horaria
 * decente (no escribirle a una paciente de madrugada) la aplica el propio
 * comando, que sí conoce la zona horaria del consultorio.
 */
Schedule::command('appointments:send-reminders')
    ->hourly()
    ->withoutOverlapping();
