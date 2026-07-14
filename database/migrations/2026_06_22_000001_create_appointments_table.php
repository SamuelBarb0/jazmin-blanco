<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();

            // Datos del paciente (copia, por si la cita no viene de un lead)
            $table->string('patient_name');
            $table->string('patient_phone')->nullable();
            $table->string('patient_email')->nullable();

            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            // scheduled, confirmed, completed, cancelled, no_show
            $table->string('status', 32)->default('scheduled');
            $table->text('notes')->nullable();

            // Sincronización con Google Calendar
            $table->string('google_event_id')->nullable()->index();
            $table->timestamp('google_synced_at')->nullable();
            $table->text('google_sync_error')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
