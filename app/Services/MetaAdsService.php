<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Lectura de campañas desde la Marketing API de Meta (Administrador de Anuncios).
 *
 * Es DISTINTA de la WhatsApp Cloud API: necesita un token con permiso ads_read
 * y el ID de la cuenta publicitaria (Ad Account ID).
 */
class MetaAdsService
{
    /**
     * Cuentas que fallaron en el último `fetchCampaigns()`, con su motivo.
     *
     * @var array<string,string>
     */
    private array $avisos = [];

    public function __construct(
        private readonly ?string $token,
        private readonly ?string $adAccountId,
        private readonly string $apiVersion = 'v21.0',
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            token: config('services.meta.ads_token'),
            adAccountId: config('services.meta.ad_account_id'),
            apiVersion: config('services.meta.api_version', 'v21.0'),
        );
    }

    public function isConfigured(): bool
    {
        return filled($this->token) && $this->adAccounts() !== [];
    }

    /**
     * Las cuentas publicitarias configuradas, normalizadas y con `act_` delante.
     *
     * `META_AD_ACCOUNT_ID` admite VARIAS separadas por coma (o punto y coma, o
     * espacios). La clínica tiene dos: la vieja, que sostiene toda la pauta que
     * hoy produce pacientes, y la nueva del portfolio propio. Con una sola,
     * apuntar a la nueva escondía las 23 campañas de la vieja, que son justo el
     * histórico contra el que se comparan los resultados.
     *
     * Esto solo afecta a la IMPORTACIÓN. La atribución de una paciente que
     * llega por un anuncio (`resolveAdCampaign`) pregunta por el ID DEL ANUNCIO
     * y no mira la cuenta en ningún momento, así que ya servía a las dos.
     *
     * @return list<string>
     */
    public function adAccounts(): array
    {
        return collect(preg_split('/[,;\s]+/', (string) $this->adAccountId))
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->map(fn (string $id) => str_starts_with($id, 'act_') ? $id : 'act_'.$id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Cuentas que fallaron en la última importación, con su motivo.
     *
     * Se guardan en vez de lanzar porque con dos cuentas el fallo de una NO
     * puede tumbar la otra: si a la cuenta nueva todavía no le pusieron el
     * permiso, seguir importando las 23 de la vieja es lo correcto. Pero
     * callárselo tampoco vale: entonces «importé y no salió la nueva» no tiene
     * explicación en ninguna pantalla.
     *
     * @return array<string,string>
     */
    public function avisos(): array
    {
        return $this->avisos;
    }

    /**
     * Trae todas las campañas reales de TODAS las cuentas configuradas.
     *
     * @return array<int,array{id:string,name:string,status:?string,objective:?string,ad_account_id:string}>
     */
    public function fetchCampaigns(): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Faltan META_ADS_TOKEN o META_AD_ACCOUNT_ID.');
        }

        $this->avisos = [];
        $campaigns = [];
        $cuentas = $this->adAccounts();

        foreach ($cuentas as $cuenta) {
            try {
                foreach ($this->fetchFromAccount($cuenta) as $c) {
                    // Indexado por id: una campaña vive en UNA cuenta, así que
                    // esto no fusiona nada real. Es un seguro por si la misma
                    // cuenta quedara listada dos veces con formatos distintos.
                    $campaigns[$c['id']] = $c;
                }
            } catch (Throwable $e) {
                $this->avisos[$cuenta] = $e->getMessage();
            }
        }

        // Que fallen TODAS sí es un error de verdad: no hay nada que importar y
        // hay que decirlo, no devolver una lista vacía como si Meta no tuviera
        // ninguna campaña.
        if ($cuentas !== [] && count($this->avisos) === count($cuentas)) {
            throw new RuntimeException(implode(' · ', array_map(
                fn (string $cuenta, string $motivo) => $cuenta.': '.$motivo,
                array_keys($this->avisos),
                array_values($this->avisos),
            )));
        }

        return array_values($campaigns);
    }

    /**
     * Las campañas de UNA cuenta, siguiendo la paginación.
     *
     * @return array<int,array{id:string,name:string,status:?string,objective:?string,ad_account_id:string}>
     */
    private function fetchFromAccount(string $account): array
    {
        $campaigns = [];
        $next = "https://graph.facebook.com/{$this->apiVersion}/{$account}/campaigns";
        $query = [
            'fields' => 'id,name,status,effective_status,objective',
            'limit' => 200,
            'access_token' => $this->token,
        ];

        // El "paging.next" ya trae la URL completa con cursor y token.
        for ($guard = 0; $next && $guard < 25; $guard++) {
            $response = Http::acceptJson()->timeout(40)->get($next, $query);
            $query = [];

            if ($response->failed()) {
                $message = $response->json('error.message') ?? 'La Marketing API rechazó la consulta.';

                throw new RuntimeException($message);
            }

            foreach ($response->json('data', []) as $c) {
                if (blank($c['id'] ?? null)) {
                    continue;
                }
                $campaigns[] = [
                    'id' => (string) $c['id'],
                    'name' => $c['name'] ?? ('Campaña '.$c['id']),
                    'status' => $c['effective_status'] ?? $c['status'] ?? null,
                    'objective' => $c['objective'] ?? null,
                    'ad_account_id' => $account,
                ];
            }

            $next = $response->json('paging.next');
        }

        return $campaigns;
    }

    /**
     * Dado el ID de un ANUNCIO (el que llega en el referral de Click-to-WhatsApp),
     * devuelve el ID de la campaña a la que pertenece, para poder emparejar el
     * lead con la campaña importada. Null si no se pudo resolver.
     */
    /**
     * La campaña padre de un anuncio: id Y nombre.
     *
     * Devuelve los dos porque con el id suelto no se puede registrar la
     * campaña con un nombre que alguien entienda, y registrarla es justo lo
     * que evita que cada anuncio acabe guardado como si fuera una campaña
     * aparte —con el mismo nombre repetido y las conversaciones repartidas
     * entre las copias—.
     *
     * @return array{id:string,name:string}|null
     */
    public function resolveAdCampaign(string $adId): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::acceptJson()->timeout(20)->get(
                "https://graph.facebook.com/{$this->apiVersion}/{$adId}",
                ['fields' => 'campaign{id,name}', 'access_token' => $this->token],
            );
        } catch (Throwable $e) {
            // `failed()` cubre los errores HTTP, pero una caída de red o un
            // timeout lanzan. Esto se llama desde el job que responde a la
            // paciente: sin este catch, que Meta tarde de más la deja sin
            // respuesta por un dato que solo sirve para atribuir la campaña.
            Log::warning('No se pudo resolver la campaña del anuncio en Meta', [
                'ad_id' => $adId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->failed() || blank($response->json('campaign.id'))) {
            return null;
        }

        return [
            'id' => (string) $response->json('campaign.id'),
            'name' => trim((string) $response->json('campaign.name')),
        ];
    }
}
