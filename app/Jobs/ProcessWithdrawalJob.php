<?php

namespace App\Jobs;

use App\Contracts\PaymentGatewayContract;
use App\Models\Transaction;
use App\Services\CarrierRouter;
use App\Support\Phone;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessWithdrawalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
     * @throws Exception
     */
    public function handle(PaymentGatewayContract $gateway, CarrierRouter $carrierRouter): void
    {
        // On ne traite la demande de débit que si elle est encore en attente
        if ($this->transaction->status !== 'pending') {
            return;
        }

        try {
            // Calcul du montant total à collecter
            $totalDebitAmount = (float) ($this->transaction->amount_sent + $this->transaction->fees);

            // 1. Normalisation du nom du pays pour éviter les erreurs de casse ou d'espaces
            $country = trim($this->transaction->country_name);

            // 2. Détermination de l'opérateur : respecte l'éventuelle bascule manuelle
            // configurée par l'admin pour ce pays (dashboard > Corridors), sinon
            // utilise l'opérateur choisi par l'expéditeur.
            $carrier = $carrierRouter->resolve($this->transaction);

            // Permet de voir exactement ce qui est envoyé au fournisseur de paiement
            logger()->info('Envoi Digitwave', [
                'ref' => $this->transaction->reference,
                'country' => $country,
                'carrier' => $carrier,
                'phone' => Phone::mask($this->transaction->recipient_phone),
                'amount' => $totalDebitAmount,
            ]);

            // Appel du fournisseur de paiement lié
            $result = $gateway->requestWithdrawal(
                $this->transaction->reference,
                $country,
                $carrier,
                $this->transaction->recipient_phone,
                $totalDebitAmount
            );

            logger()->info('Réponse Digitwave', ['ref' => $this->transaction->reference, 'response' => $result->raw]);

            if ($result->success) {
                $this->transaction->update([
                    'status' => 'processing',
                    'gateway_reference' => $result->requestId,
                ]);
            } else {
                $this->failTransaction($result->message ?? 'Rejected by operator gateway');
            }

        } catch (Exception $e) {
            logger()->error("Withdrawal Job Error [{$this->transaction->reference}]: ".$e->getMessage());
            throw $e;
        }
    }

    /**
     * Gérer le marquage de l'échec.
     */
    protected function failTransaction(string $reason): void
    {
        $this->transaction->update([
            'status' => 'failed',
            'failure_reason' => $reason,
        ]);

        // RECONCILIATION : seuls les retraits ('withdrawal') sont débités du wallet au moment
        // de la requête (TransferController::initiateWithdrawal). Les dépôts ('deposit'), traités
        // par ce même Job, ne touchent jamais le wallet à la création : les rembourser créditerait
        // un montant qui n'a jamais été prélevé.
        if ($this->transaction->type !== 'withdrawal') {
            logger()->warning("Demande échouée pour {$this->transaction->reference} (type: {$this->transaction->type}). Raison : {$reason}.");

            return;
        }

        $wallet = $this->transaction->user->wallet;
        $totalRefund = $this->transaction->amount_sent + $this->transaction->fees;

        $wallet->increment('balance', $totalRefund);

        logger()->warning("Demande de retrait échouée pour {$this->transaction->reference}. Raison : {$reason}. Utilisateur remboursé de : {$totalRefund}.");
    }

    /**
     * Action menée si le Job échoue définitivement après les 3 tentatives.
     */
    public function failed(Exception $exception): void
    {
        $this->failTransaction($exception->getMessage());
    }
}
