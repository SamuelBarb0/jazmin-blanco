<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite que la doctora tome el control de un chat: mientras `bot_enabled`
 * esté en false, el asistente deja de responder en esa conversación y contesta
 * ella desde la bandeja. En los mensajes guardamos quién los escribió, para
 * distinguir en pantalla los del bot de los de la doctora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('bot_enabled')->default(true)->after('channel');
            $table->timestamp('bot_paused_at')->nullable()->after('bot_enabled');
        });

        Schema::table('messages', function (Blueprint $table) {
            // 'bot' | 'human'. Nulo en el histórico anterior, que era todo del bot.
            $table->string('sent_by', 16)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['bot_enabled', 'bot_paused_at']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('sent_by');
        });
    }
};
