<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Id del evento de Google del que se importó esta cita (calendario
            // de origen, p. ej. el principal de la doctora). Sirve para no
            // duplicar en re-importaciones. Distinto de google_event_id, que
            // apunta al evento gestionado por la app en el calendario dedicado.
            $table->string('source_event_id')->nullable()->after('google_sync_error')->index();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['source_event_id']);
            $table->dropColumn('source_event_id');
        });
    }
};
