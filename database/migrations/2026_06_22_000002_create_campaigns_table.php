<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');                       // nombre de la campaña
            $table->string('meta_campaign_id')->nullable(); // id real de Meta (para cuando se conecte)
            $table->string('platform', 32)->default('meta'); // meta, facebook, instagram
            $table->text('offer')->nullable();            // ángulo / oferta del anuncio
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
