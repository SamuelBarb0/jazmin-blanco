<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('channel', 32)->default('manual'); // whatsapp, instagram, meta_ads, manual, otro
            $table->string('source')->nullable();              // campaña / creatividad / origen
            $table->string('service_interest')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('value', 12, 2)->nullable();        // valor estimado de la oportunidad
            $table->unsignedInteger('position')->default(0);    // orden dentro de la etapa (Kanban)
            $table->timestamp('last_contact_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'stage_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
