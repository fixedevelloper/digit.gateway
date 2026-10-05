<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletAdjustment;
use App\Notifications\AdjustmentReviewedNotification;
use App\Notifications\AdjustmentPendingNotification;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ajustements manuels de solde avec double validation (« 4 yeux »).
 *
 * Un superadmin applique directement un ajustement jusqu'à security.adjustment_approval_threshold.
 * Au-delà de ce seuil, ou pour tout ajustement demandé par un simple admin, la demande reste
 * `pending` : un AUTRE superadmin doit l'approuver (jamais son propre demandeur). Le solde n'est
 * touché qu'à l'application, sous verrou, avec re-vérification du solde pour un débit.
 */
class WalletAdjustmentService
{
    public function requiresApproval(User $requester, float $amount): bool
    {
        return $requester->role !== 'superadmin'
            || $amount > (float) config('security.adjustment_approval_threshold');
    }

    /**
     * Crée la demande et l'applique tout de suite si aucune validation n'est requise.
     *
     * @throws InvalidArgumentException solde insuffisant pour un débit appliqué immédiatement
     */
    public function request(User $requester, Wallet $wallet, string $type, float $amount, string $reason): WalletAdjustment
    {
        $adjustment = WalletAdjustment::create([
            'wallet_id' => $wallet->id,
            'admin_id' => $requester->id,
            'type' => $type,
            'amount' => $amount,
            'reason' => $reason,
            'status' => WalletAdjustment::PENDING,
        ]);

        if (! $this->requiresApproval($requester, $amount)) {
            try {
                return $this->apply($adjustment, $requester);
            } catch (InvalidArgumentException $e) {
                $adjustment->delete(); // rien d'appliqué : on ne laisse pas de trace d'une demande impossible
                throw $e;
            }
        }

        User::where('role', 'superadmin')->where('status', true)->where('id', '!=', $requester->id)->get()
            ->each(fn (User $admin) => $admin->notify(new AdjustmentPendingNotification($adjustment)));

        return $adjustment;
    }

    /**
     * @throws InvalidArgumentException auto-approbation, demande déjà traitée ou solde insuffisant
     */
    public function approve(WalletAdjustment $adjustment, User $approver): WalletAdjustment
    {
        if ($adjustment->admin_id === $approver->id) {
            throw new InvalidArgumentException('SELF_APPROVAL');
        }

        $applied = $this->apply($adjustment, $approver);
        $applied->admin?->notify(new AdjustmentReviewedNotification($applied));

        return $applied;
    }

    /** @throws InvalidArgumentException si la demande n'est plus en attente */
    public function reject(WalletAdjustment $adjustment, User $reviewer, string $reason): WalletAdjustment
    {
        $rejected = DB::transaction(function () use ($adjustment, $reviewer, $reason) {
            $locked = WalletAdjustment::whereKey($adjustment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WalletAdjustment::PENDING) {
                throw new InvalidArgumentException('Cette demande a déjà été traitée.');
            }

            $locked->update([
                'status' => WalletAdjustment::REJECTED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            return $locked;
        });

        $rejected->admin?->notify(new AdjustmentReviewedNotification($rejected));

        return $rejected;
    }

    private function apply(WalletAdjustment $adjustment, User $reviewer): WalletAdjustment
    {
        return DB::transaction(function () use ($adjustment, $reviewer) {
            $locked = WalletAdjustment::whereKey($adjustment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WalletAdjustment::PENDING) {
                throw new InvalidArgumentException('Cette demande a déjà été traitée.');
            }

            $wallet = Wallet::lockForUpdate()->findOrFail($locked->wallet_id);
            $before = (float) $wallet->balance;

            if ($locked->type === 'credit') {
                $wallet->increment('balance', $locked->amount);
            } else {
                if ($before < $locked->amount) {
                    throw new InvalidArgumentException('Solde insuffisant pour exécuter ce débit de régularisation.');
                }
                $wallet->decrement('balance', $locked->amount);
            }

            $locked->update([
                'status' => WalletAdjustment::APPROVED,
                'balance_before' => $before,
                'balance_after' => $wallet->fresh()->balance,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            return $locked;
        });
    }
}
