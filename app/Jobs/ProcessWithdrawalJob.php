<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\DigitwaveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Exception;

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
     * Exécute le Job.
     * Laravel injecte automatiquement DigitwaveService ici.
     * @param DigitwaveService $digitwaveService
     * @throws Exception
     */
    public function handle(DigitwaveService $digitwaveService): void
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

            // 2. Détermination de l'opérateur (Forcé pour le Congo)
            if (in_array($country, ['Republic of Congo', 'Congo', 'Congo-Brazzaville', 'RC'])) {
                $carrier = 'RESEAU CHARISMATIQUE';

                // Un petit log pour confirmer la redirection en production
                logger()->info("[Redirection Congo] Transaction {$this->transaction->reference} redirigée vers RESEAU CHARISMATIQUE.");
            } else {
                $carrier = $this->transaction->recipient_operator;
            }

            // LOG DEBUG : Permet de voir exactement ce qui est envoyé au SDK/Service Digitwave
            logger()->info("Envoi Digitwave", [
                'ref' => $this->transaction->reference,
                'country' => $country,
                'carrier' => $carrier,
                'phone' => $this->transaction->recipient_phone,
                'amount' => $totalDebitAmount
            ]);

            // Appel de la méthode du service
            $result = $digitwaveService->requestWithdrawal(
                $this->transaction->reference,
                $country,
                $carrier,
                $this->transaction->recipient_phone,
                $totalDebitAmount
            );

            logger()->info("Réponse Digitwave", ['ref' => $this->transaction->reference, 'response' => $result]);

            if (isset($result['success']) && $result['success'] === true) {
                $this->transaction->update([
                    'status' => 'processing',
                    'gateway_reference' => $result['request_id'] ?? null
                ]);
            } else {
                $this->failTransaction($result['message'] ?? 'Rejected by operator gateway');
            }

        } catch (Exception $e) {
            logger()->error("Withdrawal Job Error [{$this->transaction->reference}]: " . $e->getMessage());
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
            'failure_reason' => $reason
        ]);

        logger()->warning("Demande de retrait échouée pour {$this->transaction->reference}. Raison : {$reason}");
    }

    /**
     * Action menée si le Job échoue définitivement après les 3 tentatives.
     */
    public function failed(Exception $exception): void
    {
        $this->failTransaction($exception->getMessage());
    }
}
