<?php

namespace App\Services;

use App\Enums\TransferService;
use App\Exceptions\TransactionValidationException;
use App\Models\Country;
use App\Models\FeeRule;
use App\Models\Provider;

/**
 * Frais d'un service selon les règles `fee_rules` de l'admin (pays, service, provider,
 * tranche de montant, devise). La règle la plus spécifique l'emporte : pays précisé
 * avant « tous pays », provider précisé avant « tous providers », puis tranche la plus haute.
 */
class FeeCalculator
{
    public function __construct(private readonly ExchangeRateService $exchangeRates)
    {
    }

    /**
     * @param  string  $currency  devise du wallet (et du montant)
     *
     * @throws TransactionValidationException si aucune règle ne couvre ce montant
     */
    public function compute(Country $country, TransferService $service, ?Provider $provider, string $currency, float $amount): float
    {
        $rule = FeeRule::query()
            ->where('service', $service->value)
            ->where('currency', strtoupper($currency))
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('country_id')->orWhere('country_id', $country->id))
            ->where(fn ($q) => $q->whereNull('provider_id')->when($provider, fn ($q2) => $q2->orWhere('provider_id', $provider->id)))
            ->where('min_amount', '<=', $amount)
            ->where(fn ($q) => $q->whereNull('max_amount')->orWhere('max_amount', '>=', $amount))
            ->orderByRaw('country_id is null')
            ->orderByRaw('provider_id is null')
            ->orderByDesc('min_amount')
            ->first();

        if (! $rule) {
            throw TransactionValidationException::make(
                'FEE_NOT_CONFIGURED',
                'amount',
                "Aucun frais n'est configuré pour ce montant vers « {$country->name} »."
            );
        }

        return $this->exchangeRates->ceil((float) $rule->fixed_fee + $amount * (float) $rule->percent_fee, $currency);
    }
}
