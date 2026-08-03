<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los recordatorios pasan de "2 días antes + 24 h antes" a "24 h antes + 2 h
 * antes", que es lo que pidió la doctora.
 *
 * El tramo de 24 h se conserva tal cual —solo cambia de nombre— porque su
 * ventana ya era exactamente esa. El de 2 días desaparece y su columna se
 * reutiliza para el aviso nuevo de 2 h.
 *
 * ⚠️ Por eso la columna reutilizada SE VACÍA: contiene "ya se le avisó con 2
 * días de antelación", y si se dejara, toda cita que ya recibió aquel aviso
 * contaría como si también hubiera recibido el de 2 h y NUNCA lo recibiría. Las
 * citas de mañana están justo en ese caso, así que sin este paso el cambio
 * entraría en silencio y nadie notaría que falta el aviso nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->renameColumn('reminder_1d_sent_at', 'reminder_24h_sent_at');
            $table->renameColumn('reminder_2d_sent_at', 'reminder_2h_sent_at');
        });

        // La marca vieja de "2 días" no dice nada sobre el aviso de 2 horas.
        DB::table('appointments')->update(['reminder_2h_sent_at' => null]);
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->renameColumn('reminder_24h_sent_at', 'reminder_1d_sent_at');
            $table->renameColumn('reminder_2h_sent_at', 'reminder_2d_sent_at');
        });
    }
};
