<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de pago por transferencia o Nequi.
 *
 * Es la memoria de «le pedí el anticipo y estoy esperando el comprobante».
 * Antes no existía: la paciente decía «por Nequi», se le agendaba la cita en
 * el acto y el descubierto quedaba en una marca roja que nadie atendía a
 * tiempo. Ahora la cita NO nace aquí; nace cuando llega el comprobante.
 *
 * Tabla propia y no una fila más en `payment_links` a propósito: los links
 * son de la pasarela y participan en cosas que aquí no aplican —apartar el
 * hueco, consultarle el estado a Mercado Pago, agendar solos al entrar el
 * pago—. Mezclarlas obligaría a excluir las transferencias en cada uno de
 * esos sitios, y basta olvidar uno para que vuelva a agendar sin pago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();

            // El horario acordado viaja aquí y NO en la agenda: mientras no
            // haya comprobante, ese hueco le sigue saliendo libre a las demás.
            // Es la decisión explícita de la doctora: sin soporte no se aparta.
            $table->json('booking')->nullable();
            $table->unsignedInteger('amount')->nullable();

            $table->string('status', 20)->default('pending'); // pending|fulfilled|expired|cancelled
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // Por donde barre el seguimiento cada hora.
            $table->index(['status', 'expires_at']);
            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_requests');
    }
};
