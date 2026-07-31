<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas que WhatsApp rechazó.
 *
 * Meta manda estos acuses en `value.statuses` y hasta ahora solo se escribían
 * al log. Eso los volvía prácticamente invisibles: en producción `LOG_LEVEL` es
 * `error`, nadie lee el fichero a diario, y un fallo como el 131042 (la cuenta
 * sin método de pago) puede tirarse un día entero sin que nadie se entere
 * mientras las pacientes no reciben nada.
 *
 * Guardarlos permite contarlos, agruparlos por código y sacarlos en el resumen
 * diario, que es lo que convierte un fallo silencioso en un aviso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_failures', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->index();
            $table->unsignedInteger('code')->nullable()->index();
            $table->string('title')->nullable();
            $table->text('details')->nullable();
            // wamid del mensaje que falló, por si hay que rastrearlo con Meta.
            $table->string('wamid')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_failures');
    }
};
