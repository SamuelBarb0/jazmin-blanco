<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de "ya se le mandó el mensaje de reactivación" en la conversación.
 *
 * Va en `conversations` y no en `leads` porque lo que se mide es la actividad
 * del CHAT (cuánto lleva sin escribir), y porque un lead puede tener más de una
 * conversación. Es timestamp y no booleano por el mismo motivo que
 * `transfer_pending_at`: el CUÁNDO permite auditar después qué salió y qué día,
 * y deja la puerta abierta a un segundo intento sin migrar nada.
 *
 * Nace VACÍA a propósito. Eso significa que en la primera corrida TODAS las
 * conversaciones frías cuentan como pendientes de reactivar — de ahí el tope
 * por corrida del comando, que reparte ese atasco en vez de soltarlo de golpe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('reactivation_sent_at')->nullable()->after('escalation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('reactivation_sent_at');
        });
    }
};
