<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deja constancia de POR QUÉ LÍNEA entró cada conversación.
 *
 * Sin esto, cuando dos cuentas de WhatsApp quedaron suscritas al mismo webhook
 * no hubo forma de saber qué conversaciones venían de cada una: el dato existía
 * en el evento de Meta (`value.metadata.phone_number_id`) y se descartaba.
 *
 * Nullable porque lo que ya está guardado no se puede reconstruir, y porque el
 * panel (playground) no tiene línea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('phone_number_id', 32)->nullable()->after('channel');
            $table->index('phone_number_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['phone_number_id']);
            $table->dropColumn('phone_number_id');
        });
    }
};
