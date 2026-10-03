<?php

namespace App\Services;

use App\Events\TransferAssigned;
use App\Events\TransferCompleted;
use App\Events\TransferFailed;
use App\Events\TransferProcessing;
use App\Events\TransferRejected;
use App\Exceptions\InvalidTransferStateException;
use App\Models\Transaction;
use App\Models\TransferProof;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Cycle de vie d'un transfert traité manuellement :
 *
 *   pending_manual_review → assigned → processing → success | rejected | failed
 *   pending_manual_review → cancelled (par le client, tant qu'aucun agent ne l'a pris)
 *
 * Chaque transition verrouille la ligne, revérifie le statut (deux agents ne peuvent pas
 * prendre le même transfert ; un transfert final ne change plus), écrit l'audit, et un
 * rejet/échec/annulation rembourse le wallet une seule fois via TransactionStatusUpdater.
 * Les contrôles de rôle sont faits en amont (TransferPolicy) ; ce service garantit l'état.
 */
class ManualTransferService
{
    public function __construct(
        private readonly TransferAuditService $audit,
        private readonly TransactionStatusUpdater $updater,
        private readonly ProofStorageService $proofs,
    ) {}

    public function claim(Transaction $transfer, User $agent): Transaction
    {
        return $this->transition($transfer, [Transaction::STATUS_PENDING_MANUAL_REVIEW], Transaction::STATUS_ASSIGNED, $agent, TransferAuditService::ASSIGNED, event: TransferAssigned::class, apply: function (Transaction $t) use ($agent) {
            $t->assigned_agent_id = $agent->id;
        });
    }

    public function start(Transaction $transfer, User $agent): Transaction
    {
        return $this->transition($transfer, [Transaction::STATUS_ASSIGNED], 'processing', $agent, TransferAuditService::STARTED, event: TransferProcessing::class, assignedTo: $agent);
    }

    /**
     * @param  array{provider_reference?:?string,transaction_reference?:?string,comment?:?string}  $data
     */
    public function complete(Transaction $transfer, User $agent, array $data = []): Transaction
    {
        return $this->transition($transfer, ['processing'], 'success', $agent, TransferAuditService::COMPLETED, event: TransferCompleted::class, apply: function (Transaction $t) use ($data, $agent) {
            if (config('transfers.proof_required_on_complete') && ! $t->proofs()->exists()) {
                throw new InvalidTransferStateException('Une preuve de transfert est requise pour valider.', 'PROOF_REQUIRED');
            }

            $t->provider_reference = $data['provider_reference'] ?? null;
            $this->finalize($t, $agent);
        }, comment: $data['comment'] ?? null, metadata: array_filter(['transaction_reference' => $data['transaction_reference'] ?? null]), assignedTo: $agent);
    }

    public function reject(Transaction $transfer, User $agent, string $reason): Transaction
    {
        return $this->transition($transfer, ['processing'], Transaction::STATUS_REJECTED, $agent, TransferAuditService::REJECTED, event: TransferRejected::class, apply: function (Transaction $t) use ($agent, $reason) {
            $t->rejection_reason = $reason;
            $this->finalize($t, $agent);
            $this->updater->refundWallet($t);
        }, comment: $reason, assignedTo: $agent);
    }

    public function fail(Transaction $transfer, User $agent, string $reason): Transaction
    {
        return $this->transition($transfer, ['processing'], 'failed', $agent, TransferAuditService::FAILED, event: TransferFailed::class, apply: function (Transaction $t) use ($agent, $reason) {
            $t->failure_reason = $reason;
            $this->finalize($t, $agent);
            $this->updater->refundWallet($t);
        }, comment: $reason, assignedTo: $agent);
    }

    /**
     * Annulation par le client : uniquement tant qu'aucun agent n'a pris le transfert.
     */
    public function cancel(Transaction $transfer, User $customer): Transaction
    {
        return $this->transition($transfer, [Transaction::STATUS_PENDING_MANUAL_REVIEW], Transaction::STATUS_CANCELLED, $customer, TransferAuditService::CANCELLED, apply: function (Transaction $t) {
            $t->completed_at = now();
            $this->updater->refundWallet($t);
        });
    }

    /**
     * Remet un transfert dans la file : un agent rend un transfert qu'il n'a pas commencé
     * (`assigned`), ou l'administrateur reprend la main sur un transfert resté bloqué
     * (`assigned` ou `processing`, motif obligatoire : l'agent a pu commencer à l'exécuter).
     * Les fonds restent réservés ; le client peut de nouveau annuler tant qu'il est en attente.
     */
    public function release(Transaction $transfer, User $actor, ?string $reason = null): Transaction
    {
        $isAdmin = in_array($actor->role, ['admin', 'superadmin'], true);

        return $this->transition(
            $transfer,
            $isAdmin ? [Transaction::STATUS_ASSIGNED, 'processing'] : [Transaction::STATUS_ASSIGNED],
            Transaction::STATUS_PENDING_MANUAL_REVIEW,
            $actor,
            TransferAuditService::RELEASED,
            function (Transaction $t) {
                $t->assigned_agent_id = null;
            },
            comment: $reason,
            assignedTo: $isAdmin ? null : $actor,
        );
    }

    /**
     * Suspension d'un agent : ses transferts pas encore commencés retournent dans la file.
     * Ceux déjà en traitement restent à son nom (l'exécution a pu démarrer) : l'admin
     * décide, via release().
     *
     * @return array{released: int, processing: int}
     */
    public function releaseAssignedTo(User $agent, User $admin): array
    {
        $released = 0;

        Transaction::where('assigned_agent_id', $agent->id)
            ->where('status', Transaction::STATUS_ASSIGNED)
            ->get()
            ->each(function (Transaction $t) use ($admin, &$released) {
                $this->release($t, $admin, "Agent suspendu.");
                $released++;
            });

        return [
            'released' => $released,
            'processing' => Transaction::where('assigned_agent_id', $agent->id)->where('status', 'processing')->count(),
        ];
    }

    public function addProof(Transaction $transfer, User $agent, UploadedFile $file): TransferProof
    {
        return DB::transaction(function () use ($transfer, $agent, $file) {
            $locked = $this->lock($transfer);
            $this->assertAssignedTo($locked, $agent);

            if (! in_array($locked->status, [Transaction::STATUS_ASSIGNED, 'processing'], true)) {
                throw new InvalidTransferStateException('Une preuve ne peut être ajoutée que sur un transfert en cours de traitement.');
            }

            $proof = $this->proofs->store($locked, $file, $agent);
            $this->audit->record($locked, TransferAuditService::PROOF_UPLOADED, $agent, $locked->status, $locked->status, null, [
                'proof_id' => $proof->id,
                'file_name' => $proof->file_name,
            ]);

            return $proof;
        });
    }

    /**
     * @param  array<int, string>  $from  statuts source autorisés
     * @param  callable(Transaction): void|null  $apply  modifications spécifiques, dans la transaction DB
     * @param  class-string<\App\Events\TransferStatusEvent>|null  $event  événement dispatché après commit
     */
    private function transition(
        Transaction $transfer,
        array $from,
        string $to,
        User $actor,
        string $action,
        ?callable $apply = null,
        ?string $comment = null,
        array $metadata = [],
        ?User $assignedTo = null,
        ?string $event = null,
    ): Transaction {
        $result = DB::transaction(function () use ($transfer, $from, $to, $actor, $action, $apply, $comment, $metadata, $assignedTo) {
            $locked = $this->lock($transfer);

            if ($assignedTo) {
                $this->assertAssignedTo($locked, $assignedTo);
            }

            if (! in_array($locked->status, $from, true)) {
                throw new InvalidTransferStateException("Transition impossible depuis le statut « {$locked->status} ».");
            }

            $old = $locked->status;
            $locked->status = $to;

            if ($apply) {
                $apply($locked);
            }

            $locked->save();
            $this->audit->record($locked, $action, $actor, $old, $to, $comment, $metadata);

            return $locked;
        });

        // Après commit : une notification ne doit jamais précéder (ni annuler) le changement d'état.
        if ($event) {
            $event::dispatch($result);
        }

        return $result;
    }

    private function lock(Transaction $transfer): Transaction
    {
        $locked = Transaction::whereKey($transfer->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isManual()) {
            throw new InvalidTransferStateException("Ce transfert n'est pas traité manuellement.", 'NOT_MANUAL');
        }

        return $locked;
    }

    private function assertAssignedTo(Transaction $transfer, User $agent): void
    {
        if ($transfer->assigned_agent_id !== $agent->id) {
            throw new InvalidTransferStateException("Ce transfert n'est pas assigné à cet agent.", 'NOT_ASSIGNED_TO_AGENT');
        }
    }

    private function finalize(Transaction $transfer, User $agent): void
    {
        $transfer->processed_by = $agent->id;
        $transfer->processed_at = now();
        $transfer->completed_at = now();
    }
}
