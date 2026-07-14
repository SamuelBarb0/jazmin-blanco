<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Atribución del lead: de qué campaña/anuncio llegó (primer contacto).
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('stage_id')->constrained()->nullOnDelete();
        });

        // Campaña asociada a la conversación (último anuncio del que vino) +
        // copia cruda del referral de Meta (título y texto del anuncio).
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('lead_id')->constrained()->nullOnDelete();
            $table->json('referral')->nullable()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
            $table->dropColumn('campaign_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
            $table->dropColumn(['campaign_id', 'referral']);
        });
    }
};
