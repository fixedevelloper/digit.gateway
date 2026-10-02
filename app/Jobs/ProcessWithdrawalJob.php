<?php

namespace App\Jobs;

use App\Contracts\PaymentGatewayContract;
use App\Jobs\Concerns\SubmitsToGatewayOnce;
use App\Models\Transaction;
use App\Services\CarrierRouter;
use App\Support\Phone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessWithdrawalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SubmitsToGatewayOnce;

    /**
     * Le nombre de fois que le job peut être tenté.
     */
    public $tries = 3;

    /**
     * Le nombre de secondes à attendre avant de retenter le job.
     */
    public $backoff = 60;

    protected $transaction;

    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }

    /**
     * Exécute le Job. Laravel injecte le fournisseur de paiement lié dans
     * AppServiceProvider (Digitwave aujourd'hui) via le contrat, et le
     * routeur d'opérateur, tous deux automatiquement.
     *
     * @throws \Throwable
     */
    public function handle(PaymentGatewayContract $gateway, CarrierRouter $carrierRouter): void
    {
        // Montant total à collecter, dans la devise de l'opérateur. Si une conversion
        // a eu lieu (ex: wallet XAF, opérateur USD), il est déjà calculé dans
        // amount_to_receive ; sinon c'est le montant + les frais.
        $totalDebitAmount = $this->transaction->currency_received !== $this->transaction->currency_sent
            ? (float) $this->transaction->amount_to_receive
            : (float) ($this->transaction->amount_sent + $this->transaction->fees);

        // 1. Normalisation du nom du pays pour éviter les erreurs de casse ou d'espaces
        $country = trim($this->transaction->country_name);

        // 2. Détermination de l'opérateur : respecte l'éventuelle bascule manuelle
        // configurée par l'admin pour ce pays (dashboard > Corridors), sinon
        // utilise l'opérateur choisi par l'expéditeur.
        $carrier = $carrierRouter->resolve($this->transaction);

        // 3. Une seule demande de collecte par transaction : une relance du job ne doit
        // jamais débiter deux fois le compte mobile money du client.
        if (! $this->claimSubmission(['pending'])) {
            return;
        }

        // Permet de voir exactement ce qui est envoyé au fournisseur de paiement
        logger()->info('Envoi Digitwave', [
            'ref' => $this->transaction->reference,
            'country' => $country,
            'carrier' => $carrier,
            'phone' => Phone::mask($this->transaction->recipient_phone),
            'amount' => $totalDebitAmount,
            'currency' => $this->transaction->currency_received,
        ]);

        $result = $gateway->requestWithdrawal(
            $this->transaction->reference,
            $country,
            $carrier,
            $this->transaction->recipient_phone,
            $totalDebitAmount,
            $this->transaction->currency_received
        );

        logger()->info('Réponse Digitwave', ['ref' => $this->transaction->reference, 'response' => $result->raw]);

        if (! $result->success) {
            // Échec d'un dépôt : rien à rembourser (le wallet n'est crédité qu'à la
            // confirmation) ; un retrait, lui, est remboursé par TransactionStatusUpdater.
            $this->handleGatewayFailure($result, 'Rejected by operator gateway');

            return;
        }

        $this->transaction->update([
            'status' => 'processing',
            'gateway_reference' => $result->requestId,
        ]);
    }
}
