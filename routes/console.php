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
 * Cada minuto. Antes eran cinco, con el argumento de que a la paciente no le
 * cambiaba nada esperar un rato; probándolo se vio que sí le cambia: acaba de
 * pagar y se queda mirando el chat sin saber si el pago entró, que es justo el
 * momento en que uno duda de si le cobraron bien.
 *
 * Sale casi gratis porque el barrido pregunta ANTES a la base: sin links vivos
 * es una sola consulta y ni siquiera toca la pasarela; solo la consulta por los
 * links que de verdad están esperando pago, que son uno o dos como mucho.
 *
 * `withoutOverlapping` importa más ahora: si una corrida se alarga (la pasarela
 * lenta, varios links), la siguiente se salta en vez de pisarla.
 *
 * No lo hace un webhook de Mercado Pago a propósito: un barrido no depende de
 * que la notificación llegue ni de que el endpoint esté accesible, y recupera
 * solo si una corrida falla. Un webhook encima de esto bajaría la espera a
 * segundos, pero el barrido debe quedarse igual como red de seguridad.
 */
Schedule::command('payments:check-pending')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * Reactivación de quien preguntó y nunca agendó.
 *
 * Cada hora, por la misma razón que los recordatorios: el umbral se mide contra
 * "ahora", así que una corrida perdida la recupera la siguiente. La franja
 * decente (8-20, hora del consultorio) y el tope por corrida los aplica el
 * propio comando.
 *
 * Nace APAGADO (`reactivation_enabled` = 0). Encenderlo suelta el primer lote
 * sobre todo el histórico frío, así que quien lo encienda debería mirar antes
 * un `--dry-run`.
 */
Schedule::command('conversations:send-reactivation')
    ->hourly()
    ->withoutOverlapping();

/*
 * Reanudar los chats cuya pausa automática ya caducó.
 *
 * La reanudación existía desde el 26-ago, pero colgaba del mensaje ENTRANTE:
 * solo se evaluaba cuando la paciente volvía a escribir. Quien escribía una vez
 * y no insistía se quedaba mudo para siempre. Esto la saca de ahí y la mira cada
 * hora, se escriba o no.
 *
 * Cada hora y no cada minuto porque el umbral son horas de silencio: bajar la
 * frecuencia no adelanta nada y solo multiplica las corridas en vacío. Como el
 * umbral se mide contra "ahora", una corrida perdida la recupera la siguiente.
 */
Schedule::command('conversations:resume-paused')
    ->hourly()
    ->withoutOverlapping();

/*
 * Resumen diario del estado del sistema.
 *
 * A las 7 de la mañana, antes de que abra el consultorio: si algo se rompió de
 * noche, la doctora se entera al empezar el día y no por una paciente quejándose.
 *
 * La ventana es de 24 h para que encaje con la periodicidad y no se pierda nada
 * entre una corrida y la siguiente.
 */
Schedule::command('resumen:diario')
    ->dailyAt('07:00')
    ->timezone('America/Bogota')
    ->withoutOverlapping();

/*
 * Comprobantes de transferencia que no llegaron.
 *
 * Desde que la cita no se crea sin comprobante, callarse dejaría a la paciente
 * creyendo que tiene cupo. Este barrido le insiste UNA vez y, si vence el
 * plazo, le avisa que el horario se liberó.
 *
 * Cada hora y no cada minuto: los umbrales son de horas, y cada mensaje que
 * sale cuesta una conversación con Meta. Los dos plazos viven por debajo de
 * las 24 h porque pasado ese punto WhatsApp ya no deja escribir texto libre.
 */
Schedule::command('transfers:follow-up')
    ->hourly()
    ->withoutOverlapping();
