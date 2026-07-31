<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pacientes que pidieron NO recibir recordatorios de cita.
 *
 * Lore ya le promete a cada paciente que puede pedir que no le escribamos, pero
 * hasta ahora no había dónde anotarlo: el comando de recordatorios solo miraba
 * el interruptor global, la hora, el teléfono y las marcas de enviado. La
 * promesa era incumplible, y el opt-in/opt-out es justo lo que exige la política
 * de mensajería de WhatsApp para los mensajes que inicia el negocio.
 *
 * Se guarda POR TELÉFONO y no en `leads` a propósito: el destinatario del
 * recordatorio sale de `appointments.patient_phone ?: lead.phone`, así que una
 * cita sin lead vinculado se escaparía del filtro. El teléfono se normaliza a
 * sus últimos 10 dígitos, la misma regla que la lista blanca de pruebas, para
 * que dé igual cómo venga escrito el indicativo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 10);
            // 'bot' | 'panel'. Sirve para saber si lo pidió la paciente o lo
            // marcó la doctora a mano.
            $table->string('source', 16)->default('bot');
            $table->timestamps();

            $table->unique(['user_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_opt_outs');
    }
};
