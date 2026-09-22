<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo confirmó la paciente que asiste.
 *
 * No se reutiliza el estado `confirmed`: ese ya lo pone «Ya recibí el pago»
 * al verificar una transferencia, y significa «pagada», no «viene». Mezclarlos
 * haría que la doctora leyera como confirmada una cita pagada de la que nadie
 * sabe si la paciente se acuerda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('asistencia_confirmada_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('asistencia_confirmada_at');
        });
    }
};
