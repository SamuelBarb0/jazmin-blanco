<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links de pago generados para cada paciente.
 *
 * Cada link lleva una `reference` propia, así que cuando Bold confirma un pago
 * sabemos exactamente de quién era. Sin esto no se puede saber quién pagó: el
 * link fijo de antes era el mismo para todo el mundo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference')->unique();          // lo que mandamos a Bold
            $table->string('payment_link')->index();        // LNK_xxxx que devuelve Bold
            $table->string('url');
            $table->unsignedBigInteger('amount');
            $table->string('description')->nullable();

            // ACTIVE | PROCESSING | PAID | REJECTED | CANCELLED | EXPIRED
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->string('payment_method')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('checked_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_links');
    }
};
