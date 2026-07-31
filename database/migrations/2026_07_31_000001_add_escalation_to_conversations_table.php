<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escalamiento a humano pedido por el propio asistente.
 *
 * Ya existía la pausa manual (`bot_enabled` + `bot_paused_at`): la doctora entra
 * a un chat y toma el control. Lo que faltaba era el camino contrario, que es el
 * que exige la política de mensajería de WhatsApp: que el asistente tenga una
 * ruta "rápida, clara y directa" para pasarle la conversación a una persona.
 *
 * Se guarda aparte de la pausa manual a propósito: pausar es una decisión de la
 * doctora, escalar es una alerta que ALGUIEN TIENE QUE ATENDER. Sin distinguirlas,
 * un chat escalado se ve igual que uno que ella pausó a propósito y se pierde
 * entre los demás.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable()->after('bot_paused_at');
            $table->string('escalation_reason', 500)->nullable()->after('escalated_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['escalated_at', 'escalation_reason']);
        });
    }
};
