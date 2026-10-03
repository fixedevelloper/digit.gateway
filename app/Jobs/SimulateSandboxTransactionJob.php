<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\TransactionStatusUpdater;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Traite une transaction sandbox sans jamais appeler Digitwave : le résultat est
 * simulé d'après la fin du numéro du destinataire (numéro de compte pour un virement bancaire), pour que le marchand puisse
 * tester chaque cas de son intégration (documenté dans l'API marchande) :
 *
 *   - se termine par FAILURE_SUFFIX ('0002') : échec (transfert/retrait remboursés) ;
 *   - se termine par PENDING_SUFFIX ('0003') : reste en cours indéfiniment ;
 *   - tout autre numéro : succès (un dépôt crédite le solde sandbox).
 *
 * Les mouvements de solde passent par TransactionStatusUpdater, qui ne touche que
 * wallets.sandbox_balance pour une transaction sandbox.
 */
class SimulateSandboxTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const FAILURE_SUFFIX = '0002';

    public const PENDING_SUFFIX = '0003';

    public function __construct(public Transaction $transaction) {}

    public function handle(TransactionStatusUpdater $updater): void
    {
        $transaction = $this->transaction->fresh();

        if (! $transaction || $transaction->environment !== 'sandbox') {
            logger()->critical('[SANDBOX] Simulation refusée : la transaction n\'est pas une transaction sandbox.', [
                'ref' => $this->transaction->reference,
            ]);

            return;
        }

        if (! in_array($transaction->status, ['pending', 'processing'])) {
            return;
        }

        $transaction->update([
            'status' => 'processing',
            'gateway_reference' => 'SBX-'.strtoupper(Str::random(12)),
            'submitted_at' => now(),
        ]);

        // Virement bancaire : le scénario se pilote par la fin du numéro de compte du bénéficiaire.
        $phone = $transaction->bank_beneficiary_id
            ? (string) $transaction->bankBeneficiary?->account_number
            : (string) $transaction->recipient_phone;

        if (str_ends_with($phone, self::PENDING_SUFFIX)) {
            return;
        }

        if (str_ends_with($phone, self::FAILURE_SUFFIX)) {
            $updater->apply($transaction, 'failed', 'Échec simulé (sandbox).');

            return;
        }

        $updater->apply($transaction, 'success');
    }
}
