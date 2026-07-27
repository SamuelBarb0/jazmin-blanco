<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\User;
use App\Support\PatientLeads;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Lleva al pipeline las pacientes que solo existían en la agenda.
 *
 * Las citas importadas del Google Calendar de la doctora entraron sin lead
 * (solo el título del evento), así que sus pacientes reales no aparecían en el
 * CRM. Este comando las agrupa por persona, descarta los eventos que no son
 * pacientes y crea/vincula un lead por cada una. Es idempotente: una cita que
 * ya tiene lead no se vuelve a tocar.
 */
class BackfillPatientLeads extends Command
{
    protected $signature = 'crm:backfill-leads
                            {--user= : Id o correo de la doctora (por defecto, todas)}
                            {--dry-run : Muestra lo que haría sin escribir nada}';

    protected $description = 'Crea leads en el pipeline a partir de las citas que no tienen paciente vinculado';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $users = $this->resolveUsers();
        if ($users->isEmpty()) {
            $this->error('No se encontró la doctora indicada.');

            return self::FAILURE;
        }

        foreach ($users as $user) {
            $this->backfill($user, $dry);
        }

        if ($dry) {
            $this->newLine();
            $this->warn('Simulación: no se escribió nada. Corre el comando sin --dry-run para aplicarlo.');
        }

        return self::SUCCESS;
    }

    private function backfill(User $user, bool $dry): void
    {
        $this->newLine();
        $this->info("── {$user->name} <{$user->email}> ──");

        // Un lead sin etapa no aparece en ninguna columna del Kanban: la doctora
        // no lo ve nunca. Los rescatamos a «Nuevo».
        $huerfanos = $user->leads()->whereNull('stage_id')->get();
        if ($huerfanos->isNotEmpty()) {
            $nuevo = PatientLeads::stageId($user, 'nuevo');
            if (! $dry) {
                foreach ($huerfanos as $lead) {
                    $lead->forceFill([
                        'stage_id' => $nuevo,
                        'position' => PatientLeads::nextPosition($user, $nuevo),
                    ])->save();
                }
            }
            $this->line("  Leads sin etapa movidos a «Nuevo»: {$huerfanos->count()} ({$huerfanos->pluck('name')->implode(', ')})");
        }

        $sueltas = $user->appointments()
            ->whereNull('lead_id')
            ->orderBy('starts_at')
            ->get();

        if ($sueltas->isEmpty()) {
            $this->line('  Todas las citas ya tienen paciente vinculado.');

            return;
        }

        // ── 1. Descartar los marcadores del calendario ───────────────
        $descartadas = $sueltas->filter(
            fn (Appointment $a) => blank($a->patient_phone) && PatientLeads::isNonPatient($a->patient_name)
        );
        $citas = $sueltas->reject(fn (Appointment $a) => $descartadas->contains($a));

        // ── 2. Agrupar las citas por persona ─────────────────────────
        $grupos = $this->agrupar($citas);

        // ── 3. Crear o vincular un lead por grupo ────────────────────
        $pool = $user->leads()->get();
        $creados = 0;
        $vinculados = 0;
        $filas = [];

        foreach ($grupos as $grupo) {
            /** @var Collection<int,Appointment> $grupo */
            $nombre = $this->mejorNombre($grupo);
            $telefono = $grupo->pluck('patient_phone')->filter()->first();
            $ultima = $grupo->max('starts_at');

            // Si su próxima cita está por venir sigue Agendada; si ya pasó,
            // la paciente fue atendida (etapa ganada).
            $etapa = $ultima->isFuture() ? 'agendado' : 'cerrado';

            $lead = PatientLeads::find($user, $nombre, $telefono, $pool);
            $nuevo = ! $lead;

            if ($dry) {
                $filas[] = [$nuevo ? 'crear' : 'vincular', PatientLeads::pretty($nombre), $grupo->count(), $nuevo ? $etapa : ($lead->stage?->slug ?? '—')];
                $nuevo ? $creados++ : $vinculados++;

                continue;
            }

            if ($nuevo) {
                $stageId = PatientLeads::stageId($user, $etapa);
                $lead = $user->leads()->create([
                    'stage_id' => $stageId,
                    'name' => PatientLeads::pretty($nombre),
                    'phone' => $telefono,
                    'channel' => 'manual',
                    'source' => 'agenda',
                    'service_interest' => $grupo->pluck('service.name')->filter()->first(),
                    'notes' => $this->resumen($grupo),
                    'position' => PatientLeads::nextPosition($user, $stageId),
                    'last_contact_at' => $ultima->isPast() ? $ultima : $grupo->min('starts_at'),
                ]);
                $pool->push($lead);
                $creados++;
            } else {
                // Lead que ya existía (del bot o creado a mano): se respeta su
                // etapa actual, solo se le completan los datos que le falten.
                $cambios = [];
                if (blank($lead->phone) && filled($telefono)) {
                    $cambios['phone'] = $telefono;
                }
                if (blank($lead->last_contact_at)) {
                    $cambios['last_contact_at'] = $ultima;
                }
                if ($cambios) {
                    $lead->forceFill($cambios)->save();
                }
                $vinculados++;
            }

            Appointment::whereIn('id', $grupo->pluck('id'))->update(['lead_id' => $lead->id]);
            $filas[] = [$nuevo ? 'creado' : 'vinculado', $lead->name, $grupo->count(), $nuevo ? $etapa : ($lead->stage?->slug ?? '—')];
        }

        // ── 4. Reporte ───────────────────────────────────────────────
        $this->newLine();
        $this->table(['Acción', 'Paciente', 'Citas', 'Etapa'], $filas);

        $fusiones = $this->fusiones($grupos);
        if ($fusiones) {
            $this->newLine();
            $this->line('  <comment>Variantes del mismo nombre que se unieron (revísalas):</comment>');
            foreach ($fusiones as $nombre => $variantes) {
                $this->line("    {$nombre}  ←  ".implode(' · ', $variantes));
            }
        }

        if ($descartadas->isNotEmpty()) {
            $this->newLine();
            $this->line('  <comment>Eventos descartados por no ser pacientes:</comment> '
                .$descartadas->pluck('patient_name')->unique()->implode(', ')
                ." ({$descartadas->count()} citas)");
        }

        $this->newLine();
        $this->info("  Pacientes creados: {$creados} · vinculados a un lead existente: {$vinculados} · citas descartadas: {$descartadas->count()}");
    }

    /**
     * Agrupa las citas por persona. Recorre en orden y mete cada cita en el
     * primer grupo cuyo nombre o teléfono coincida.
     *
     * @param  Collection<int,Appointment>  $citas
     * @return Collection<int,Collection<int,Appointment>>
     */
    private function agrupar(Collection $citas): Collection
    {
        /** @var Collection<int,Collection<int,Appointment>> $grupos */
        $grupos = collect();

        foreach ($citas as $cita) {
            $tel = PatientLeads::digits($cita->patient_phone);

            $grupo = $grupos->first(function (Collection $g) use ($cita, $tel) {
                if (strlen($tel) >= 7 && $g->contains(fn (Appointment $a) => PatientLeads::digits($a->patient_phone) === $tel)) {
                    return true;
                }

                return $g->contains(fn (Appointment $a) => PatientLeads::sameName($a->patient_name, $cita->patient_name));
            });

            $grupo ? $grupo->push($cita) : $grupos->push(collect([$cita]));
        }

        return $grupos;
    }

    /**
     * Nombre que representa al grupo: la variante más repetida; si empatan, la
     * de la cita más reciente (suele ser la grafía corregida) y por último la
     * más completa.
     *
     * @param  Collection<int,Appointment>  $grupo
     */
    private function mejorNombre(Collection $grupo): string
    {
        $variantes = [];
        foreach ($grupo as $cita) {
            $nombre = trim(preg_replace('/\s+/', ' ', (string) $cita->patient_name));
            if ($nombre === '') {
                continue;
            }
            $v = $variantes[$nombre] ?? ['veces' => 0, 'ultima' => $cita->starts_at];
            $variantes[$nombre] = [
                'veces' => $v['veces'] + 1,
                'ultima' => $cita->starts_at->max($v['ultima']),
            ];
        }

        return collect($variantes)
            ->sortByDesc(fn ($v, $nombre) => [$v['veces'], $v['ultima']->timestamp, mb_strlen($nombre)])
            ->keys()
            ->first() ?? '';
    }

    /**
     * @param  Collection<int,Appointment>  $grupo
     */
    private function resumen(Collection $grupo): string
    {
        $primera = $grupo->min('starts_at')->format('d/m/Y');
        $ultima = $grupo->max('starts_at')->format('d/m/Y');
        $n = $grupo->count();

        return $n > 1
            ? "Paciente de la agenda: {$n} citas entre {$primera} y {$ultima}."
            : "Paciente de la agenda: cita del {$primera}.";
    }

    /**
     * Grupos donde el nombre venía escrito de varias formas.
     *
     * @param  Collection<int,Collection<int,Appointment>>  $grupos
     * @return array<string,list<string>>
     */
    private function fusiones(Collection $grupos): array
    {
        $out = [];
        foreach ($grupos as $grupo) {
            $variantes = $grupo->pluck('patient_name')->map(fn ($n) => trim((string) $n))->unique()->values();
            if ($variantes->count() > 1) {
                $out[PatientLeads::pretty($this->mejorNombre($grupo))] = $variantes->all();
            }
        }

        return $out;
    }

    /**
     * @return Collection<int,User>
     */
    private function resolveUsers(): Collection
    {
        $ref = $this->option('user');

        if (blank($ref)) {
            return User::all();
        }

        return User::query()
            ->when(is_numeric($ref), fn ($q) => $q->whereKey($ref), fn ($q) => $q->where('email', $ref))
            ->get();
    }
}
