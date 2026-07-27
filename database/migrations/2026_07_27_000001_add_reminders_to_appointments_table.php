<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marcas de los recordatorios ya enviados al paciente por WhatsApp.
 * Sirven para no repetirlos: cada cita recibe como máximo uno de cada tipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('reminder_2d_sent_at')->nullable()->after('google_sync_error');
            $table->timestamp('reminder_1d_sent_at')->nullable()->after('reminder_2d_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['reminder_2d_sent_at', 'reminder_1d_sent_at']);
        });
    }
};
