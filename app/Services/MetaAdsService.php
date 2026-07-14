<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Lectura de campañas desde la Marketing API de Meta (Administrador de Anuncios).
 *
 * Es DISTINTA de la WhatsApp Cloud API: necesita un token con permiso ads_read
 * y el ID de la cuenta publicitaria (Ad Account ID).
 */
class MetaAdsService
{
    public function __construct(
        private readonly ?string $token,
        private readonly ?string $adAccountId,
        private readonly string $apiVersion = 'v21.0',
    ) {
    }

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
        return filled($this->token) && filled($this->adAccountId);
    }

    /**
     * Trae todas las campañas reales de la cuenta publicitaria (con paginación).
     *
     * @return array<int,array{id:string,name:string,status:?string,objective:?string}>
     */
    public function fetchCampaigns(): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Faltan META_ADS_TOKEN o META_AD_ACCOUNT_ID.');
        }

        $account = str_starts_with($this->adAccountId, 'act_')
            ? $this->adAccountId
            : 'act_'.$this->adAccountId;

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
    public function resolveAdCampaignId(string $adId): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $response = Http::acceptJson()->timeout(20)->get(
            "https://graph.facebook.com/{$this->apiVersion}/{$adId}",
            ['fields' => 'campaign{id,name}', 'access_token' => $this->token],
        );

        if ($response->failed()) {
            return null;
        }

        return $response->json('campaign.id');
    }
}
