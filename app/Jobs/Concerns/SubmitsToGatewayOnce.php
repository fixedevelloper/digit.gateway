<?php

namespace App\Jobs\Concerns;

use App\Models\Transaction;
use App\Services\Gateways\GatewayResponse;
use App\Services\TransactionStatusUpdater;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Garantit qu'une transaction n'est soumise qu'une seule fois à Digitwave, même si le
 * job est relancé (échec, timeout du worker...), et qu'aucun remboursement automatique
 * n'a lieu tant que le sort de l'opération chez Digitwave est inconnu.
 *
 * Les remboursements passent tous par TransactionStatusUpdater (ligne verrouillée,
 * statut revérifié) : jamais deux fois, jamais sur une transaction déjà finalisée.
 *
 * Attend une propriété $transaction (Transaction) sur le job.
 */
trait SubmitsToGatewayOnce
{
    /**
     * Réserve la soumission sous verrou en posant submitted_at. Retourne false si la
     * transaction a déjà été soumise ou n'est plus dans un statut éligible.
     *
     * @param  array<int, string>  $eligibleStatuses
     */
    protected function claimSubmission(array $eligibleStatuses): bool
    {
        return DB::transaction(function () use ($eligibleStatuses) {
            $transaction = Transaction::whereKey($this->transaction->id)->lockForUpdate()->first();

            if (! $transaction || $transaction->submitted_at || ! in_array($transaction->status, $eligibleStatuses)) {
                return false;
            }

            // Garde-fou : une transaction sandbox n'est jamais envoyée à Digitwave
            // (elle est traitée par SimulateSandboxTransactionJob).
            if ($transaction->environment === 'sandbox') {
                logger()->critical("[SANDBOX] Envoi Digitwave refusé pour la transaction sandbox {$transaction->reference}.");

                return false;
            }

            $transaction->update(['submitted_at' => now()]);
            $this->transaction = $transaction;

            return true;
        });
    }

    /**
     * Refus explicite : échec + remboursement éventuel. Résultat inconnu : la transaction
     * reste en cours, à confirmer par le webhook ou à rapprocher manuellement.
     */
    protected function handleGatewayFailure(GatewayResponse $result, string $defaultReason): void
    {
        if ($result->uncertain) {
            logger()->critical("[RAPPROCHEMENT REQUIS] Résultat Digitwave inconnu pour {$this->transaction->reference} : aucun remboursement automatique.", [
                'ref' => $this->transaction->reference,
                'message' => $result->message,
            ]);

            return;
        }

        app(TransactionStatusUpdater::class)->apply($this->transaction, 'failed', $result->message ?? $defaultReason);
    }

    /**
     * Échec définitif du job. Remboursable uniquement si rien n'a été soumis à Digitwave.
     */
    public function failed(?Throwable $exception): void
    {
        $transaction = $this->transaction->fresh();

        if (! $transaction) {
            return;
        }

        if ($transaction->submitted_at) {
            logger()->critical("[RAPPROCHEMENT REQUIS] Job en échec après soumission à Digitwave pour {$transaction->reference} : aucun remboursement automatique.", [
                'ref' => $transaction->reference,
                'exception' => $exception?->getMessage(),
            ]);

            return;
        }

        app(TransactionStatusUpdater::class)->apply($transaction, 'failed', $exception?->getMessage() ?? 'Échec du traitement.');
    }
}
