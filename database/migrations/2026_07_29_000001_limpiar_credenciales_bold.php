<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El consultorio pasó de Bold a Mercado Pago. Las llaves de Bold quedaron
 * guardadas y cifradas en `settings`, donde ya no las lee nadie: se borran para
 * no dejar credenciales vivas de una pasarela que no se usa.
 *
 * No tiene vuelta atrás porque los valores están cifrados y no se pueden
 * reconstruir; si se volviera a Bold, habría que pegarlos de nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->whereIn('key', ['bold_identity_key', 'bold_secret_key'])->delete();
    }

    public function down(): void
    {
        // Nada que restaurar.
    }
};
