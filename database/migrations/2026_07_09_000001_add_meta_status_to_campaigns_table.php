<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Estado real que trae Meta al importar (effective_status): ACTIVE,
            // PAUSED, ARCHIVED, DELETED, IN_PROCESS, WITH_ISSUES… Null para las
            // campañas creadas a mano (no vienen de la Marketing API).
            $table->string('meta_status', 40)->nullable()->after('platform');
            $table->index(['user_id', 'meta_status']);
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'meta_status']);
            $table->dropColumn('meta_status');
        });
    }
};
