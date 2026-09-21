<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El acuse de recibo del webhook: qué nos entregó Meta y qué hicimos con ello.
 *
 * El 21-sep-2026 una paciente escribió cinco veces en tres días y no obtuvo
 * respuesta. En la base no había NI RASTRO de esos mensajes: ni el texto, ni el
 * chat, ni un error. Como el número vive en la nube de Meta y no en un teléfono,
 * nadie —tampoco la doctora— podía ver esos mensajes en ninguna parte, y a Meta
 * no se le puede pedir el historial de lo que nos entregó.
 *
 * Quedaban dos explicaciones indistinguibles desde fuera:
 *   a) Meta nunca nos entregó el evento.
 *   b) Nos lo entregó y lo perdimos nosotros.
 *
 * Esta tabla las separa. Se escribe una fila por evento ANTES de cualquier
 * filtro —antes de la deduplicación, del interruptor del bot, de la lista de
 * prueba y de la pausa del chat— y el job la va marcando con lo que acabó
 * haciendo. Si una paciente dice «escribí y nadie me contestó», la respuesta
 * está aquí: o no hay fila (no llegó, y es de Meta hacia fuera) o la hay con el
 * motivo exacto.
 *
 * Es deliberadamente barata: unas pocas columnas, sin cuerpo del mensaje. No
 * es un archivo de conversaciones —eso ya es `messages`—, es la prueba de
 * entrega.
 *
 * Solo anota lo que ENTRA. Los acuses de los mensajes que enviamos nosotros ya
 * tienen su sitio: los fallos en `delivery_failures` y el resto en el log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_hits', function (Blueprint $table) {
            $table->id();

            // El id del mensaje en Meta. Es la única llave que comparten su
            // lado y el nuestro, y la que permite rastrear un caso concreto.
            $table->string('wamid')->nullable()->index();

            // Quién escribió y a qué línea nuestra. `from` se guarda tal cual
            // lo manda Meta, sin normalizar: si algún día el problema es el
            // formato del número, normalizarlo aquí borraría justo la pista.
            $table->string('from_phone', 32)->nullable()->index();
            $table->string('phone_number_id', 32)->nullable();

            // El tipo que declara Meta: text, image, audio, button…
            $table->string('tipo', 24)->nullable();

            // Qué acabó pasando. Ver WebhookHit::RESULTADO_*.
            $table->string('resultado', 32)->default('recibido')->index();
            $table->string('detalle')->nullable();

            // A qué conversación fue a parar, cuando se supo.
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // La consulta de verdad es «¿llegó algo de este número estos días?».
            $table->index(['from_phone', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_hits');
    }
};
