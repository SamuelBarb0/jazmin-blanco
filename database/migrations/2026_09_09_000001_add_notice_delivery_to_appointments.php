<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Si el aviso de WhatsApp de una cita llegó de verdad, o rebotó.
 *
 * Hasta ahora «enviado» quería decir «Meta nos respondió 200», que no es lo
 * mismo: la Cloud API acepta el envío al instante y solo DESPUÉS, por el
 * webhook de `statuses`, dice si el mensaje se pudo entregar. Entre las dos
 * cosas pueden pasar segundos —en la cita del 9-sep-2026 fueron 19— y en ese
 * hueco la pantalla ya le había dicho a la doctora «Se le avisó por WhatsApp».
 * El rebote (`131026`, número inexistente) solo quedó en `laravel.log` y en
 * `delivery_failures`, que nadie mira a diario, así que la paciente se quedó
 * sin saber de su cita y en el CRM todo parecía correcto.
 *
 * `notice_wamid` es el hilo que faltaba: el acuse de Meta no trae la cita ni la
 * conversación, solo ese código, de modo que sin guardarlo no hay forma de
 * saber A QUÉ cita se refiere un rebote. `delivery_failures` ya guardaba los
 * fallos, pero sueltos; esto es lo que los ata a la cita concreta.
 *
 * `notice_failed_at` + `notice_failure` son lo que se pinta en la agenda. Van
 * en la cita y no se resuelven consultando `delivery_failures` porque el
 * teléfono cambia: en cuanto la doctora corrige el número —justo lo que hay que
 * hacer tras un rebote— ya no habría manera de emparejarlos.
 *
 * Los tres en null = no hay nada raro que contar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('notice_wamid')->nullable()->index()->after('transfer_pending_at');
            $table->timestamp('notice_failed_at')->nullable()->after('notice_wamid');
            $table->string('notice_failure')->nullable()->after('notice_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['notice_wamid', 'notice_failed_at', 'notice_failure']);
        });
    }
};
