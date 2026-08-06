<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Varias personas trabajando sobre la MISMA clínica.
 *
 * Hasta ahora había una sola cuenta y todo colgaba de ella: pacientes, citas,
 * conversaciones, servicios. Crear un usuario nuevo no daba acceso a nada, daba
 * un sistema vacío.
 *
 * LA DECISIÓN DE DISEÑO, que es lo que hace esto barato y seguro: los datos NO
 * se mueven. Cada fila conserva su `user_id` apuntando a la doctora. Lo que se
 * agrega es `users.cuenta_id`: de qué clínica es cada persona. La doctora se
 * apunta a sí misma; quien se sume al equipo apunta a ella.
 *
 * Con eso, las relaciones del modelo pasan a leer por `cuenta_id` en vez de por
 * `id` y las 68 consultas repartidas por controladores, comandos y servicios
 * siguen escritas igual y empiezan a devolver los datos de la clínica. Mover
 * los datos habría exigido tocarlas una por una, y bastaba olvidar una para que
 * una pantalla enseñara de menos —o de más— sin avisar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nulable de entrada porque las filas que ya existen no tienen
            // valor; se rellena abajo y recién entonces se puede exigir.
            $table->unsignedBigInteger('cuenta_id')->nullable()->after('id');

            // Quien puede administrar el equipo. La doctora es dueña de su
            // clínica; el resto entra invitado.
            $table->boolean('es_propietario')->default(false)->after('cuenta_id');

            // Quitar el acceso sin borrar a la persona: si se borrara, se
            // perdería el rastro de lo que hizo.
            $table->boolean('activo')->default(true)->after('es_propietario');
        });

        // Todo el que ya tenía cuenta es dueño de la suya.
        DB::table('users')->update([
            'cuenta_id' => DB::raw('id'),
            'es_propietario' => true,
        ]);

        // Se queda NULABLE a propósito: el id no existe hasta después de
        // insertar, así que una cuenta nueva no puede apuntarse a sí misma en
        // el mismo INSERT. Lo rellena `User::booted()` justo después de crear,
        // y así ningún `User::create()` del proyecto necesita saber de esto.
        Schema::table('users', function (Blueprint $table) {
            $table->index('cuenta_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['cuenta_id']);
            $table->dropColumn(['cuenta_id', 'es_propietario', 'activo']);
        });
    }
};
