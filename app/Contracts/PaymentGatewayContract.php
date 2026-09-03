<?php

namespace App\Contracts;

use App\Services\Gateways\GatewayResponse;

/**
 * Contrat commun à tout fournisseur de paiement mobile money (Digitwave
 * aujourd'hui, un futur agrégateur MTN MoMo / Orange Money demain). Les Jobs
 * et Commands dépendent de cette interface plutôt que d'une implémentation
 * concrète — ajouter un fournisseur ne demande pas de toucher à leur code,
 * seulement le binding dans AppServiceProvider (ou un résolveur par pays/
 * opérateur le jour où plusieurs fournisseurs coexistent).
 */
interface PaymentGatewayContract
{
    /**
     * Envoyer de l'argent (Transfert / Payout).
     */
    public function sendMoney(string $reference, string $country, string $carrier, string $number, float $amount): GatewayResponse;

    /**
     * Demander un retrait (Collecte / Cash-In).
     */
    public function requestWithdrawal(string $reference, string $country, string $carrier, string $number, float $amount): GatewayResponse;

    /**
     * Vérifier le statut d'une requête déjà soumise.
     */
    public function checkStatus(string $requestId): GatewayResponse;
}
