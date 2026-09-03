<?php

namespace App\Services;

use App\Models\Country;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

/**
 * Détermine l'opérateur mobile money réel à utiliser pour router une transaction.
 * Logique de routage interne à Digit (bascule d'urgence admin) — indépendante du
 * fournisseur de paiement appelé ensuite (Digitwave ou un futur agrégateur), donc
 * volontairement en dehors du contrat PaymentGatewayContract.
 */
class CarrierRouter
{
    /**
     * Si l'admin a configuré manuellement un "opérateur forcé" pour le pays de la
     * transaction (bascule d'urgence depuis le dashboard, ex: un opérateur mobile
     * money indisponible), celui-ci prend le pas sur l'opérateur choisi par
     * l'expéditeur. Sinon, l'opérateur choisi par l'expéditeur est utilisé tel quel.
     */
    public function resolve(Transaction $transaction): string
    {
        $countryName = trim($transaction->country_name ?? '');

        $forcedOperator = Country::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($countryName)])
            ->first()
            ?->forcedOperator;

        if ($forcedOperator && $forcedOperator->status) {
            Log::info("[Routage manuel admin] Transaction {$transaction->reference} redirigée vers l'opérateur forcé '{$forcedOperator->code}' pour '{$countryName}'.");

            return $forcedOperator->code;
        }

        return $transaction->recipient_operator;
    }
}
