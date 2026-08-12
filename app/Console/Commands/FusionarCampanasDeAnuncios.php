<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\MetaAdsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Junta las filas que en realidad son ANUNCIOS con su campaña de verdad.
 *
 * Durante un tiempo, cuando llegaba alguien de un anuncio cuya campaña no
 * estaba importada todavía, se registraba una fila por ANUNCIO usando el id
 * del anuncio en `meta_campaign_id`. Resultado: la misma campaña aparecía
 * varias veces con el nombre repetido, las conversaciones quedaban repartidas
 * entre las copias, y al importar después entraba una fila más —la real— con
 * cero chats.
 *
 * Esto lo deja en su sitio: una fila por campaña, con todo colgando de ella.
 * El job ya no crea filas así, o sea que esto se corre UNA vez.
 */
class FusionarCampanasDeAnuncios extends Command
{
    protected $signature = 'campanas:fusionar-anuncios
                            {--dry-run : Enseña lo que haría, sin tocar nada}';

    protected $description = 'Junta las campañas que en realidad son anuncios con su campaña real de Meta';

    public function handle(): int
    {
        $seco = (bool) $this->option('dry-run');
        $ads = MetaAdsService::fromConfig();

        if (! $ads->isConfigured()) {
            $this->error('Meta Ads no está conectado: sin eso no se puede saber a qué campaña pertenece cada anuncio.');

            return self::FAILURE;
        }

        $filas = Campaign::query()->whereNotNull('meta_campaign_id')->get();
        $this->info(($seco ? 'SIMULACIÓN · ' : '')."Revisando {$filas->count()} campañas guardadas…");
        $this->newLine();

        $fusionadas = 0;
        $movidos = ['conversaciones' => 0, 'leads' => 0, 'media' => 0];

        // En seco no se escribe nada, así que la primera fila de una campaña
        // no llega a convertirse en el destino de las siguientes. Sin llevar
        // la cuenta aquí, la simulación diría «se convierte en la campaña
        // real» por cada anuncio del mismo grupo —cinco veces cuando en la
        // corrida real solo pasa dos—, y una simulación que miente es peor
        // que no tenerla.
        $destinosPrevistos = [];

        foreach ($filas as $fila) {
            $padre = $ads->resolveAdCampaign((string) $fila->meta_campaign_id);

            // Si Meta no le encuentra campaña padre, es que ya ES una campaña.
            if (! $padre) {
                continue;
            }

            // La fila de destino: la campaña real, ya guardada o recién creada.
            $destino = Campaign::query()
                ->where('user_id', $fila->user_id)
                ->where('meta_campaign_id', $padre['id'])
                ->first();

            if (! $destino && isset($destinosPrevistos[$padre['id']])) {
                $destino = $destinosPrevistos[$padre['id']];
            }

            $this->line("  <fg=yellow>#{$fila->id}</> «".Str::limit((string) $fila->name, 34).'» es un ANUNCIO de «'
                .Str::limit($padre['name'], 34).'»');

            if ($destino && $destino->id === $fila->id) {
                continue;
            }

            $conv = $fila->conversations()->count();
            $leads = $fila->leads()->count();
            $media = $fila->media()->count();

            $this->line(sprintf('      %s  ·  %d conversaciones, %d leads, %d media',
                $destino ? "se une a la fila #{$destino->id}" : 'se convierte en la campaña real',
                $conv, $leads, $media));

            if ($seco) {
                if (! $destino) {
                    $destinosPrevistos[$padre['id']] = $fila;
                }
                $fusionadas++;

                continue;
            }

            DB::transaction(function () use ($fila, $destino, $padre, &$movidos, $conv, $leads, $media) {
                if (! $destino) {
                    // No existe la campaña real: esta misma fila pasa a serlo.
                    // Así no se pierde nada de lo que ya cuelga de ella.
                    $fila->meta_campaign_id = $padre['id'];
                    $fila->name = $padre['name'] !== '' ? Str::limit($padre['name'], 250, '') : $fila->name;
                    $fila->save();

                    return;
                }

                // La oferta del anuncio es mejor que nada: si la campaña real
                // no tiene (las importadas vienen sin ella), se hereda.
                if (blank($destino->offer) && filled($fila->offer)) {
                    $destino->offer = $fila->offer;
                }
                // Lo mismo con el servicio, si alguien lo asignó a mano.
                if (blank($destino->service_id) && filled($fila->service_id)) {
                    $destino->service_id = $fila->service_id;
                }
                $destino->save();

                $fila->conversations()->update(['campaign_id' => $destino->id]);
                $fila->leads()->update(['campaign_id' => $destino->id]);
                $fila->media()->update(['campaign_id' => $destino->id]);

                $movidos['conversaciones'] += $conv;
                $movidos['leads'] += $leads;
                $movidos['media'] += $media;

                // Ya no cuelga nada: la fila duplicada se va.
                $fila->delete();
            });

            $fusionadas++;
        }

        $this->newLine();

        if ($fusionadas === 0) {
            $this->info('No hay nada que fusionar: todas las filas son campañas de verdad.');

            return self::SUCCESS;
        }

        $this->info(($seco ? 'SIMULACIÓN · ' : '')."Filas que eran anuncios: {$fusionadas}.");

        if (! $seco) {
            $this->line(sprintf('  Movidos: %d conversaciones, %d leads, %d media.',
                $movidos['conversaciones'], $movidos['leads'], $movidos['media']));
        }

        return self::SUCCESS;
    }
}
