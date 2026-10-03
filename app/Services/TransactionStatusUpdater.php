<?php

namespace App\Services;

use App\Events\TransactionStatusUpdated;
use App\Events\TransferCompleted;
use App\Events\TransferFailed;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applique un statut Digitwave (issu du polling `transaction:status` ou du
 * webhook `/webhooks/digitwave`) à une transaction : crédit du wallet pour un
 * dépôt réussi, remboursement pour un transfert/retrait échoué. Les deux
 * chemins d'entrée appellent ce même code pour ne jamais diverger sur cette
 * logique critique (voir CheckTransactionStatus et WebhookController).
 */
class TransactionStatusUpdater
{
    /**
     * @param  string  $apiStatus  Statut brut renvoyé par Digitwave (ex: 'SUCCESS', 'Failed'...),
     *                             comparé insensible à la casse.
     */
    public function apply(Transaction $transaction, string $apiStatus, ?string $message = null): void
    {
        $apiStatus = strtolower(trim($apiStatus));

        $updated = DB::transaction(function () use ($transaction, $apiStatus, $message) {
            // Recharger pour verrouiller la ligne et vérifier l'état actuel : une
            // transaction déjà finalisée (par l'autre chemin, ou un rejeu du
            // webhook) ne doit jamais être retraitée.
            $transaction = Transaction::where('id', $transaction->id)->lockForUpdate()->first();

            if (! in_array($transaction->status, ['pending', 'processing'])) {
                return null;
            }

            // Une transaction sandbox ne crédite/rembourse que le solde fictif.
            $balanceColumn = Wallet::balanceColumn($transaction->environment);

            if (in_array($apiStatus, ['success', 'successful', 'completed'])) {
                $transaction->update(['status' => 'success']);

                // Créditer uniquement si c'est un dépôt
                if ($transaction->type === 'deposit') {
                    $transaction->user->wallet()->increment($balanceColumn, $transaction->amount_sent);
                }
            } elseif (in_array($apiStatus, ['failed', 'failure', 'rejected', 'declined'])) {
                $transaction->update([
                    'status' => 'failed',
                    'failure_reason' => $message ?? 'Rejeté par l\'opérateur',
                ]);

                // Remboursement automatique : uniquement pour transfer/withdrawal, qui
                // débitent le wallet dès l'initiation (TransferController). Un dépôt ne
                // débite jamais rien à la création — le rembourser créditerait un montant
                // qui n'a jamais été prélevé.
                if (in_array($transaction->type, ['transfer', 'withdrawal'])) {
                    $this->refundWallet($transaction);
                }
            } else {
                return null;
            }

            return $transaction;
        });

        // Diffusé après le commit : une indisponibilité de Reverb ne doit jamais annuler
        // la mise à jour du statut ni le remboursement.
        if ($updated) {
            if ($updated->type === 'transfer') {
                $updated->status === 'success' ? TransferCompleted::dispatch($updated) : TransferFailed::dispatch($updated);
            }

            try {
                TransactionStatusUpdated::dispatch($updated);
            } catch (Throwable $e) {
                Log::warning("[TransactionStatusUpdater] Diffusion temps réel impossible pour {$updated->reference} : ".$e->getMessage());
            }
        }
    }

    /**
     * Rend au wallet le montant débité à l'initiation (montant + frais). À n'appeler que
     * dans la transaction DB qui fait passer la transaction à un statut final, sur une
     * ligne verrouillée : c'est ce qui garantit un remboursement unique. Partagé avec le
     * traitement manuel (ManualTransferService).
     */
    public function refundWallet(Transaction $transaction): void
    {
        $transaction->user->wallet()->increment(
            Wallet::balanceColumn($transaction->environment),
            $transaction->amount_sent + $transaction->fees
        );
    }
}
