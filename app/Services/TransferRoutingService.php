<?php

namespace App\Services;

use App\Enums\CountryServiceStatus;
use App\Enums\ProcessingMode;
use App\Enums\TransferService;
use App\Exceptions\TransactionValidationException;
use App\Models\Country;
use App\Models\CountryService;
use App\Models\Provider;

/**
 * Point central de décision AUTOMATIC / MANUAL d'un transfert, à partir de la
 * configuration administrateur (country_services + providers). Aucune logique de
 * routage ne doit vivre dans les controllers.
 *
 * Règles :
 *  - service du pays INACTIVE            → refusé ;
 *  - service du pays MANUAL              → manuel ;
 *  - service ACTIVE + provider actif, compatible et réellement implémenté
 *    (config/transfers.php)              → automatique ;
 *  - service ACTIVE sans provider exploitable → manuel (jamais d'appel à un provider
 *    qui ne peut pas traiter le transfert) ;
 *  - Mobile Money sans configuration     → comportement historique : Digitwave, sauf si
 *    le provider « digitwave » est désactivé par l'admin (interrupteur global) ;
 *  - Bank transfer sans configuration    → refusé (service à activer explicitement).
 */
class TransferRoutingService
{
    /**
     * @throws TransactionValidationException si le service n'est pas disponible pour ce pays
     */
    public function route(Country $country, TransferService $service): RoutingDecision
    {
        $config = CountryService::query()
            ->with('provider')
            ->where('country_id', $country->id)
            ->where('service', $service->value)
            ->first();

        if (! $config) {
            return $this->routeWithoutConfiguration($country, $service);
        }

        return match ($config->status) {
            CountryServiceStatus::Inactive => throw $this->unavailable($country, $service),
            CountryServiceStatus::Manual => new RoutingDecision(ProcessingMode::Manual, null, $config),
            CountryServiceStatus::Active => $this->usable($config->provider, $service)
                ? new RoutingDecision(ProcessingMode::Automatic, $config->provider, $config)
                : new RoutingDecision(ProcessingMode::Manual, null, $config),
        };
    }

    private function routeWithoutConfiguration(Country $country, TransferService $service): RoutingDecision
    {
        if ($service !== TransferService::MobileMoney) {
            throw $this->unavailable($country, $service);
        }

        $provider = Provider::where('code', config('transfers.default_mobile_money_provider'))->first();

        // Aucune ligne `providers` : déploiement historique, Digitwave reste le circuit par défaut.
        if (! $provider) {
            return new RoutingDecision(ProcessingMode::Automatic);
        }

        return $this->usable($provider, $service)
            ? new RoutingDecision(ProcessingMode::Automatic, $provider)
            : new RoutingDecision(ProcessingMode::Manual);
    }

    private function usable(?Provider $provider, TransferService $service): bool
    {
        return $provider
            && $provider->active
            && $provider->supports($service->value)
            && array_key_exists($provider->code, config("transfers.gateways.{$service->value}", []));
    }

    private function unavailable(Country $country, TransferService $service): TransactionValidationException
    {
        return TransactionValidationException::make(
            'SERVICE_UNAVAILABLE',
            'country',
            "Le service {$service->value} n'est pas disponible pour « {$country->name} »."
        );
    }
}
