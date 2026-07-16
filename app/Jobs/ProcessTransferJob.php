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

class ProcessTransferJob implements ShouldQueue
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

    /**
     * Crée une nouvelle instance de Job.
     * @param Transaction $transaction
     */
    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }

    /**
     * Exécute le Job.
     * * Laravel injecte automatiquement DigitwaveService ici.
     * @param DigitwaveService $digitwaveService
     * @throws \Throwable
     */
    public function handle(DigitwaveService $digitwaveService): void
    {
        // Éviter de traiter une transaction qui n'est pas en attente/processing
        if (!in_array($this->transaction->status, ['pending', 'processing'])) {
            return;
        }

        // 1. Normalisation du nom du pays pour éviter les erreurs de casse ou d'espaces
        $country = trim($this->transaction->country_name);

        // 1. Log d'entrée pour vérifier si le Job se lance bien
        logger()->info("[JOB HANDLE START] Début d'exécution pour la transaction : " . ($this->transaction->reference ?? 'SANS_REF'));

        // 2. Log de contrôle du statut et du pays
        logger()->info("[JOB DEBUG STATUS/COUNTRY]", [
            'statut_actuel' => $this->transaction->status ?? 'NON_DEFINI',
            'pays_brut' => $this->transaction->country_name ?? 'NON_DEFINI',
        ]);
        try {
            // 2. Détermination de l'opérateur (Forcé pour le Congo)
            if (in_array($country, ['Republic of Congo', 'Congo', 'Congo-Brazzaville', 'RC'])) {
                $carrier = 'RESEAU CHARISMATIQUE';

                // Un petit log pour confirmer la redirection en production
                logger()->info("[Redirection Congo - Envoi] Transaction {$this->transaction->reference} redirigée vers RESEAU CHARISMATIQUE.");
            } else {
                $carrier = $this->transaction->recipient_operator;
            }

            // LOG DEBUG : Suivi précis de la payload sortante vers Digitwave
            logger()->info("Envoi transfert Digitwave", [
                'ref' => $this->transaction->reference,
                'country_sent' => $country,
                'carrier_sent' => $carrier,
                'phone' => $this->transaction->recipient_phone,
                'amount' => (float) $this->transaction->amount_to_receive
            ]);

            // Utilisation du service centralisé (avec $country nettoyé et $carrier déterminé)
            $result = $digitwaveService->sendMoney(
                $this->transaction->reference,
                $country,
                $carrier,
                $this->transaction->recipient_phone,
                (float) $this->transaction->amount_to_receive
            );

            logger()->info("Réponse Digitwave Envoi", ['ref' => $this->transaction->reference, 'response' => $result]);

            if (isset($result['success']) && $result['success'] === true) {
                // On extrait les données utiles (dépend du format retourné par Digitwave)
                // Si l'API renvoie un statut immédiat comme 'Success' ou 'Successful'
                $apiStatus = strtoupper($result['data']['status'] ?? $result['status'] ?? 'PROCESSING');

                if ($apiStatus === 'SUCCESS' || $apiStatus === 'SUCCESSFUL') {
                    $this->transaction->update([
                        'status' => 'success',
                        'gateway_reference' => $result['request_id'] ?? $result['data']['request_id'] ?? null
                    ]);
                } else {
                    // Statut intermédiaire, en attente du Webhook
                    $this->transaction->update([
                        'status' => 'processing',
                        'gateway_reference' => $result['request_id'] ?? $result['data']['request_id'] ?? null
                    ]);
                }
            } else {
                // L'API a répondu avec un code d'erreur ou success: false
                $this->failTransaction($result['message'] ?? 'Erreur retournée par l\'API Digitwave.');
            }

        } catch (Exception $e) {
            // Journaliser l'erreur interne de communication
            logger()->error("Erreur lors du traitement du transfert {$this->transaction->reference} : " . $e->getMessage());
            // 2. Log de contrôle du statut et du pays
            logger()->info("[JOB DEBUG STATUSEND/COUNTRY]", [
                'statut_actuel' => $this->transaction->status ?? 'NON_DEFINI',

            // Lever l'exception permet à Laravel de replacer le job dans la file (Queue) pour une nouvelle tentative
            throw $e;
        }
    }
    /**
     * Gérer l'échec définitif du transfert (Remboursement).
     * @param string $reason
     */
    protected function failTransaction(string $reason): void
    {
        $this->transaction->update([
            'status' => 'failed',
            'failure_reason' => $reason
        ]);

        // RECONCILIATION : Recréditer le portefeuille de l'utilisateur
        $wallet = $this->transaction->user->wallet;
        $totalRefund = $this->transaction->amount_sent + $this->transaction->fees;

        $wallet->increment('balance', $totalRefund);

        logger()->warning("Transfert échoué {$this->transaction->reference}. Utilisateur remboursé de : {$totalRefund} XAF");
    }

    /**
     * Action à mener si le Job échoue définitivement après toutes les tentatives (3 essais).
     */
    public function failed(Exception $exception): void
    {
        $this->failTransaction($exception->getMessage());
    }
}
