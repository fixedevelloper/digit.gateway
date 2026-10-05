<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Rapprochement des transactions dont l'issue chez Digitwave est incertaine.
 *
 *  - unknown_outcome : soumise à Digitwave mais sans référence retournée (timeout, 5xx).
 *    Digitwave n'accepte pas notre référence : aucune vérification automatique possible,
 *    un humain doit confirmer côté Digitwave puis trancher (résolution manuelle).
 *  - stale : référence connue mais toujours en cours après STALE_AFTER_MINUTES ; le cron
 *    `transaction:reconcile` re-vérifie auprès de Digitwave, sinon elle est signalée.
 *
 * Aucun remboursement ni versement n'est automatique ici : seule la résolution explicite
 * (success/failed) passe par TransactionStatusUpdater, qui verrouille la ligne.
 */
class ReconciliationService
{
    public const UNKNOWN_AFTER_MINUTES = 5;

    public const STALE_AFTER_MINUTES = 60;

    public const REASON_UNKNOWN = 'unknown_outcome';

    public const REASON_STALE = 'stale';

    public function __construct(
        private readonly TransactionStatusUpdater $updater,
        private readonly TransferAuditService $audit,
    ) {}

    /** Transactions réelles en attente de rapprochement. */
    public function query(): Builder
    {
        return Transaction::where('environment', 'production')
            ->whereIn('status', ['pending', 'processing'])
            ->whereNotNull('submitted_at')
            ->where(function (Builder $q) {
                $q->where(fn (Builder $q) => $q
                    ->where(fn (Builder $q) => $q->whereNull('gateway_reference')->orWhere('gateway_reference', ''))
                    ->where('submitted_at', '<=', now()->subMinutes(self::UNKNOWN_AFTER_MINUTES)))
                    ->orWhere(fn (Builder $q) => $q
                        ->whereNotNull('gateway_reference')->where('gateway_reference', '!=', '')
                        ->where('submitted_at', '<=', now()->subMinutes(self::STALE_AFTER_MINUTES)));
            });
    }

    public function reasonFor(Transaction $transaction): string
    {
        return $transaction->gateway_reference ? self::REASON_STALE : self::REASON_UNKNOWN;
    }

    /**
     * Tranche une transaction : 'success' (Digitwave a bien payé : pas de remboursement ;
     * un dépôt est crédité) ou 'failed' (rien n'est parti : remboursement). Tracé dans
     * le journal d'audit avec l'admin et sa note.
     *
     * @throws InvalidArgumentException si la transaction n'est plus à l'état en cours
     */
    public function resolve(Transaction $transaction, string $outcome, User $admin, string $note): Transaction
    {
        $oldStatus = DB::transaction(fn () => Transaction::whereKey($transaction->id)->lockForUpdate()->value('status'));

        if (! in_array($oldStatus, ['pending', 'processing'], true)) {
            throw new InvalidArgumentException("La transaction est déjà au statut « {$oldStatus} ».");
        }

        $this->updater->apply($transaction, $outcome, $note);

        $fresh = $transaction->fresh();

        if ($fresh->status === $oldStatus) {
            throw new InvalidArgumentException('Résolution refusée : la transaction a été traitée entre-temps.');
        }

        $this->audit->record(
            $fresh,
            'RECONCILIATION_RESOLVED',
            $admin,
            $oldStatus,
            $fresh->status,
            $note,
            ['outcome' => $outcome],
        );

        return $fresh;
    }
}
