<?php

namespace App\Services;

use App\Events\TransactionStatusUpdated;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

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

        DB::transaction(function () use ($transaction, $apiStatus, $message) {
            // Recharger pour verrouiller la ligne et vérifier l'état actuel : une
            // transaction déjà finalisée (par l'autre chemin, ou un rejeu du
            // webhook) ne doit jamais être retraitée.
            $transaction = Transaction::where('id', $transaction->id)->lockForUpdate()->first();

            if (! in_array($transaction->status, ['pending', 'processing'])) {
                return;
            }

            if (in_array($apiStatus, ['success', 'successful', 'completed'])) {
                $transaction->update(['status' => 'success']);

                // Créditer uniquement si c'est un dépôt
                if ($transaction->type === 'deposit') {
                    $transaction->user->wallet()->increment('balance', $transaction->amount_sent);
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
                    $refundAmount = $transaction->amount_sent + $transaction->fees;
                    $transaction->user->wallet()->increment('balance', $refundAmount);
                }
            } else {
                return;
            }

            TransactionStatusUpdated::dispatch($transaction);
        });
    }
}
