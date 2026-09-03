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
     */
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
        // Normalisation du nom du pays pour éviter les erreurs de casse ou d'espaces
        $country = trim($this->transaction->country_name ?? '');

        // Éviter de traiter une transaction qui n'est pas en attente/processing
        if (! in_array($this->transaction->status, ['pending', 'processing'])) {
            logger()->warning('[JOB TERMINATED] Annulation : Statut non éligible pour le traitement', [
                'ref' => $this->transaction->reference,
                'status' => $this->transaction->status,
            ]);

            return;
        }

        try {
            // 1. Détermination de l'opérateur : respecte l'éventuelle bascule manuelle
            // configurée par l'admin pour ce pays (dashboard > Corridors), sinon
            // utilise l'opérateur choisi par l'expéditeur.
            $carrier = $carrierRouter->resolve($this->transaction);

            // Suivi précis de la payload sortante vers Digitwave
            logger()->info('Envoi transfert Digitwave', [
                'ref' => $this->transaction->reference,
                'country_sent' => $country,
                'carrier_sent' => $carrier,
                'phone' => Phone::mask($this->transaction->recipient_phone),
                'amount' => (float) $this->transaction->amount_to_receive,
            ]);

            // Utilisation du fournisseur de paiement lié (avec $country nettoyé et $carrier déterminé)
            $result = $gateway->sendMoney(
                $this->transaction->reference,
                $country,
                $carrier,
                $this->transaction->recipient_phone,
                (float) $this->transaction->amount_to_receive
            );

            logger()->info('Réponse Digitwave Envoi', ['ref' => $this->transaction->reference, 'response' => $result->raw]);

            if ($result->success) {
                // Si l'API renvoie un statut immédiat comme 'Success' ou 'Successful'
                $apiStatus = $result->status ?? 'PROCESSING';

                if ($apiStatus === 'SUCCESS' || $apiStatus === 'SUCCESSFUL') {
                    $this->transaction->update([
                        'status' => 'success',
                        'gateway_reference' => $result->requestId,
                    ]);
                } else {
                    // Statut intermédiaire, en attente de la confirmation finale
                    $this->transaction->update([
                        'status' => 'processing',
                        'gateway_reference' => $result->requestId,
                    ]);
                }
            } else {
                // L'API a répondu avec un code d'erreur ou success: false
                $this->failTransaction($result->message ?? 'Erreur retournée par l\'API Digitwave.');
            }

        } catch (Exception $e) {
            // Journaliser l'erreur interne de communication
            logger()->error("Erreur lors du traitement du transfert {$this->transaction->reference} : ".$e->getMessage(), [
                'statut_actuel' => $this->transaction->status ?? 'NON_DEFINI',
            ]);

            // Lever l'exception permet à Laravel de replacer le job dans la file (Queue) pour une nouvelle tentative
            throw $e;
        }
    }

    /**
     * Gérer l'échec définitif du transfert (Remboursement).
     */
    protected function failTransaction(string $reason): void
    {
        $this->transaction->update([
            'status' => 'failed',
            'failure_reason' => $reason,
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
