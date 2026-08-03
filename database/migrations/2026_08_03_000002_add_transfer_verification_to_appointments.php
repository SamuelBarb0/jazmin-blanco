<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Citas apartadas con transferencia, pendientes de que la doctora confirme que
 * el dinero llegó.
 *
 * Hasta ahora una cita solo existía si la pasarela confirmaba el pago. Con
 * transferencia no hay nada que consultar —el abono aparece en el banco de la
 * doctora, no en un API—, así que la cita se crea igual para no dejar el cupo
 * suelto mientras alguien lo verifica a mano, pero queda MARCADA.
 *
 * Es un timestamp y no un booleano porque el CUÁNDO importa: dice desde cuándo
 * lleva esperando verificación, y con eso el resumen diario puede avisar de las
 * que llevan demasiado.
 *
 * `null` = nada que verificar (pagó por la pasarela, o la doctora ya confirmó).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('transfer_pending_at')->nullable()->after('reminder_24h_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('transfer_pending_at');
        });
    }
};
