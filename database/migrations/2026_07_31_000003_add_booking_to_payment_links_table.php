<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda, junto al link de pago, la cita que la paciente ya había elegido.
 *
 * Hasta ahora el horario acordado solo existía dentro de la conversación, así
 * que nadie más podía agendar: hacía falta que la paciente volviera a escribir
 * "ya pagué" para que el bot comprobara el pago. Si pagaba y no escribía, no
 * pasaba nada — ni cita, ni aviso, ni rastro.
 *
 * Con `booking` el dato queda fuera de la conversación y el barrido automático
 * (payments:check-pending) puede agendar solo. `appointment_id` cierra el
 * círculo y evita la cita duplicada si además la paciente escribe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->json('booking')->nullable()->after('description');
            $table->foreignId('appointment_id')->nullable()->after('booking')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('appointment_id');
            $table->dropColumn('booking');
        });
    }
};
