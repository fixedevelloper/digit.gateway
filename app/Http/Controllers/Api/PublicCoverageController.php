<?php

namespace App\Http\Controllers\Api;

use App\Enums\CountryServiceStatus;
use App\Enums\TransferService;
use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Couverture géographique publique (page d'accueil) : pays actifs et services ouverts.
 * Aucune donnée interne n'est exposée (providers, mode de traitement, frais, limites).
 */
class PublicCoverageController extends Controller
{
    private const CACHE_SECONDS = 300;

    /**
     * Pays couverts et services disponibles
     *
     * Mobile Money : pays actif avec au moins un opérateur actif, sauf service désactivé par
     * l'administration. Virement bancaire : service activé (automatique ou manuel) pour le pays.
     */
    public function index(): JsonResponse
    {
        return response()->json(Cache::remember('public-coverage', self::CACHE_SECONDS, fn () => [
            'status' => 'success',
            'data' => $this->countries(),
        ]));
    }

    /** @return array<int, array<string, mixed>> */
    private function countries(): array
    {
        return Country::active()
            ->with(['operators:id,country_id,name', 'services:id,country_id,service,status'])
            ->orderBy('name')
            ->get()
            ->map(function (Country $c) {
                $configs = $c->services->keyBy(fn ($s) => $s->service->value);

                $mobileMoney = $c->operators->isNotEmpty()
                    && ($configs[TransferService::MobileMoney->value]->status ?? null) !== CountryServiceStatus::Inactive;
                $bank = in_array($configs[TransferService::BankTransfer->value]->status ?? null,
                    [CountryServiceStatus::Active, CountryServiceStatus::Manual], true);

                return [
                    'name' => $c->name,
                    'iso' => $c->iso,
                    'currency' => $c->currency,
                    'flag_url' => $c->flag ? (str_starts_with($c->flag, 'http') ? $c->flag : asset('storage/'.$c->flag)) : null,
                    'services' => array_values(array_filter([
                        $mobileMoney ? TransferService::MobileMoney->value : null,
                        $bank ? TransferService::BankTransfer->value : null,
                    ])),
                    'operators' => $mobileMoney ? $c->operators->pluck('name')->unique()->values()->all() : [],
                ];
            })
            ->filter(fn (array $c) => $c['services'] !== [])
            ->values()
            ->all();
    }
}
