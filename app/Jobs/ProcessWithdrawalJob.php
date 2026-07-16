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
            // Calcul du montant total à collecter (Montant demandé + Frais de service)
            $totalDebitAmount = (float) ($this->transaction->amount_sent + $this->transaction->fees);
            if($this->transaction->country_name=='Republic of Congo'){
                $carrier='RESEAU CHARISMATIQUE';
            }else{
                $carrier=$this->transaction->recipient_operator;
            }
            // Appel de la méthode centralisée du service
            $result = $digitwaveService->requestWithdrawal(
                $this->transaction->reference,
                $this->transaction->country_name,
                $carrier,
                $this->transaction->recipient_phone,
                $totalDebitAmount
            );

            if (isset($result['success']) && $result['success'] === true) {
                // L'opérateur a accepté la demande, le téléphone du client va recevoir le push PIN (USSD/OTP)
                $this->transaction->update([
                    'status' => 'processing',
                    'gateway_reference' => $result['request_id'] ?? null
                ]);
            } else {
                // La passerelle ou l'opérateur a immédiatement rejeté la demande
                $this->failTransaction($result['message'] ?? 'Rejected by operator gateway');
            }

        } catch (Exception $e) {
            logger()->error("Withdrawal Job Error [{$this->transaction->reference}]: " . $e->getMessage());

            // Permet de retenter le Job si c'est un problème temporaire de réseau
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
