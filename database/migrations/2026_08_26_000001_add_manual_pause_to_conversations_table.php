<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue las dos formas de pausar al asistente, que hasta ahora eran la
 * misma columna y no lo son.
 *
 * Escribirle a mano a una paciente pausa a Lore para que no conteste encima.
 * Eso está bien mientras dura la conversación, pero la pausa se quedaba puesta
 * para siempre: días después la paciente escribía «necesito reprogramarla» y
 * no le respondía nadie, porque el asistente seguía callado desde aquella vez.
 *
 * Con esta marca, la pausa del botón «Pausar a Lore» es una decisión y se
 * respeta hasta que la doctora la deshaga; la que se pone sola al escribir
 * caduca cuando el chat lleva horas quieto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('bot_paused_manually')->default(false)->after('bot_paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('bot_paused_manually');
        });
    }
};
