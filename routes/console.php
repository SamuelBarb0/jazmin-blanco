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

/*
 * Pagos pendientes: agenda la cita en cuanto entra el pago.
 *
 * Cada cinco minutos, no cada minuto: el link vive 24 horas y a la paciente no
 * le cambia nada esperar unos minutos, mientras que consultar la pasarela por
 * cada link vivo sesenta veces por hora no aporta.
 *
 * No lo hace un webhook de Mercado Pago a propósito: un barrido no depende de
 * que la notificación llegue ni de que el endpoint esté accesible, y recupera
 * solo si una corrida falla.
 */
Schedule::command('payments:check-pending')
    ->everyFiveMinutes()
    ->withoutOverlapping();
