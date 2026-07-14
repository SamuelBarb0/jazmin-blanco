<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\Service;
use App\Models\Stage;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CrmSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'jazmin@consultorio.test'],
            ['name' => 'Dra. Jasmin Blanco', 'password' => Hash::make('jazmin2026')],
        );

        // ── Etapas del pipeline ──────────────────────────────
        $stages = [
            ['name' => 'Nuevo', 'color' => 'sky', 'is_won' => false, 'is_lost' => false],
            ['name' => 'Calificando', 'color' => 'violet', 'is_won' => false, 'is_lost' => false],
            ['name' => 'Interesado', 'color' => 'blue', 'is_won' => false, 'is_lost' => false],
            ['name' => 'Agendado', 'color' => 'cyan', 'is_won' => false, 'is_lost' => false],
            ['name' => 'En valoración', 'color' => 'amber', 'is_won' => false, 'is_lost' => false],
            ['name' => 'Cerrado', 'color' => 'emerald', 'is_won' => true, 'is_lost' => false],
            ['name' => 'Perdido', 'color' => 'rose', 'is_won' => false, 'is_lost' => true],
        ];
        $stageIds = [];
        foreach ($stages as $i => $s) {
            $stage = $user->stages()->firstOrCreate(
                ['slug' => str($s['name'])->slug()],
                ['name' => $s['name'], 'color' => $s['color'], 'position' => $i, 'is_won' => $s['is_won'], 'is_lost' => $s['is_lost']],
            );
            $stageIds[$s['name']] = $stage->id;
        }

        // ── Etiquetas ────────────────────────────────────────
        $tags = [
            ['name' => 'VIP', 'color' => 'amber'],
            ['name' => 'Recurrente', 'color' => 'emerald'],
            ['name' => 'Primera vez', 'color' => 'sky'],
            ['name' => 'Urgente', 'color' => 'rose'],
            ['name' => 'Capilar', 'color' => 'violet'],
            ['name' => 'Antienvejecimiento', 'color' => 'cyan'],
        ];
        $tagModels = [];
        foreach ($tags as $t) {
            $tagModels[$t['name']] = $user->tags()->firstOrCreate(
                ['slug' => str($t['name'])->slug()],
                ['name' => $t['name'], 'color' => $t['color']],
            );
        }

        // ── Servicio de ejemplo (base de conocimiento) ───────
        $user->services()->firstOrCreate(
            ['slug' => 'limpieza-facial-profunda'],
            ['name' => 'Limpieza facial profunda', 'category' => 'Facial', 'short_description' => 'Hidratación y limpieza', 'price' => 120000, 'duration_minutes' => 45, 'is_active' => true],
        );

        // ── Leads demo ───────────────────────────────────────
        if ($user->leads()->count() > 0) {
            return;
        }

        $leads = [
            ['Valentina Ríos', '3104567890', 'whatsapp', 'FUE Capilar', 'Nuevo', 4200000, ['Capilar', 'Primera vez']],
            ['Camila Torres', '3119876543', 'instagram', 'Endolifting', 'Nuevo', 1800000, ['Antienvejecimiento']],
            ['Andrés Gómez', '3201234567', 'meta_ads', 'Programa Metabólico', 'Nuevo', 2500000, []],
            ['Daniela Patiño', '3156677889', 'whatsapp', 'Mesoterapia', 'Calificando', 900000, ['Primera vez']],
            ['Sofía Mendoza', '3145566778', 'instagram', 'Plasma rico en plaquetas', 'Calificando', 1200000, ['VIP']],
            ['Mariana Castro', '3168899001', 'meta_ads', 'Exosomas', 'Interesado', 2100000, ['Antienvejecimiento']],
            ['Laura Jiménez', '3132211009', 'whatsapp', 'FUE Capilar', 'Interesado', 4500000, ['Capilar', 'VIP']],
            ['Juliana Vargas', '3177788990', 'instagram', 'Endolifting', 'Agendado', 1800000, ['Recurrente']],
            ['Paula Restrepo', '3101122334', 'whatsapp', 'Antienvejecimiento integral', 'Agendado', 2600000, ['VIP', 'Antienvejecimiento']],
            ['Natalia Ospina', '3155544332', 'meta_ads', 'Programa Metabólico', 'En valoración', 2500000, ['Urgente']],
            ['Isabela Cárdenas', '3188877665', 'whatsapp', 'Mesoterapia', 'En valoración', 950000, []],
            ['Gabriela Suárez', '3122233445', 'instagram', 'FUE Capilar', 'Cerrado', 4800000, ['Capilar', 'Recurrente']],
            ['Verónica Lozano', '3199988776', 'whatsapp', 'Plasma rico en plaquetas', 'Cerrado', 1200000, ['VIP']],
            ['Carolina Méndez', '3144433221', 'meta_ads', 'Endolifting', 'Perdido', 1800000, []],
        ];

        $positions = [];
        foreach ($leads as $row) {
            [$name, $phone, $channel, $service, $stageName, $value, $leadTags] = $row;
            $pos = $positions[$stageName] = ($positions[$stageName] ?? -1) + 1;

            $lead = $user->leads()->create([
                'stage_id' => $stageIds[$stageName],
                'name' => $name,
                'phone' => $phone,
                'channel' => $channel,
                'service_interest' => $service,
                'value' => $value,
                'position' => $pos,
                'last_contact_at' => now()->subDays(rand(0, 12)),
            ]);

            $lead->tags()->sync(collect($leadTags)->map(fn ($t) => $tagModels[$t]->id)->all());
        }
    }
}
