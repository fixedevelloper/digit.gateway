<?php

namespace App\Jobs;

use App\Contracts\PaymentGatewayContract;
use App\Enums\ProcessingMode;
use App\Jobs\Concerns\SubmitsToGatewayOnce;
use App\Models\Transaction;
use App\Services\CarrierRouter;
use App\Services\TransactionStatusUpdater;
use App\Support\Phone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessTransferJob implements ShouldQueue
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
        // Garde-fou : un transfert routé en manuel (ou sans mode automatique) n'est jamais
        // envoyé à un provider, même si le job est dispatché par erreur.
        if ($this->transaction->processing_mode !== ProcessingMode::Automatic) {
            logger()->warning('[JOB TERMINATED] Transfert non automatique : aucun appel au provider', [
                'ref' => $this->transaction->reference,
                'mode' => $this->transaction->processing_mode?->value,
            ]);

            return;
        }

        // Normalisation du nom du pays pour éviter les erreurs de casse ou d'espaces
        $country = trim($this->transaction->country_name ?? '');

        // 1. Détermination de l'opérateur : respecte l'éventuelle bascule manuelle
        // configurée par l'admin pour ce pays (dashboard > Corridors), sinon
        // utilise l'opérateur choisi par l'expéditeur. Fait avant la réservation :
        // une erreur ici laisse la transaction non soumise, donc relançable.
        $carrier = $carrierRouter->resolve($this->transaction);

        // 2. Une seule soumission par transaction : une relance du job après envoi
        // ne doit jamais verser une seconde fois.
        if (! $this->claimSubmission(['pending', 'processing'])) {
            logger()->warning('[JOB TERMINATED] Transaction déjà soumise ou statut non éligible', [
                'ref' => $this->transaction->reference,
                'status' => $this->transaction->status,
            ]);

            return;
        }

        // Suivi précis de la payload sortante vers Digitwave
        logger()->info('Envoi transfert Digitwave', [
            'ref' => $this->transaction->reference,
            'country_sent' => $country,
            'carrier_sent' => $carrier,
            'phone' => Phone::mask($this->transaction->recipient_phone),
            'amount' => (float) $this->transaction->amount_to_receive,
            'currency' => $this->transaction->currency_received,
        ]);

        $result = $gateway->sendMoney(
            $this->transaction->reference,
            $country,
            $carrier,
            $this->transaction->recipient_phone,
            (float) $this->transaction->amount_to_receive,
            $this->transaction->currency_received
        );

        logger()->info('Réponse Digitwave Envoi', ['ref' => $this->transaction->reference, 'response' => $result->raw]);

        if (! $result->success) {
            $this->handleGatewayFailure($result, 'Erreur retournée par l\'API Digitwave.');

            return;
        }

        $this->transaction->update([
            'status' => 'processing',
            'gateway_reference' => $result->requestId,
        ]);

        // Statut immédiat (ex: SUCCESS) appliqué par le même chemin que le webhook et le
        // cron ; un statut intermédiaire laisse la transaction en 'processing'.
        app(TransactionStatusUpdater::class)->apply($this->transaction, $result->status ?? 'PROCESSING');
    }
}
