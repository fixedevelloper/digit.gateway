<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransferAuditLog;
use App\Models\User;

/**
 * Écriture du journal d'audit des transferts (TRANSFER_CREATED, TRANSFER_ASSIGNED...).
 */
class TransferAuditService
{
    public const CREATED = 'TRANSFER_CREATED';

    public const ASSIGNED = 'TRANSFER_ASSIGNED';

    public const STARTED = 'TRANSFER_STARTED';

    public const COMPLETED = 'TRANSFER_COMPLETED';

    public const REJECTED = 'TRANSFER_REJECTED';

    public const FAILED = 'TRANSFER_FAILED';

    public const CANCELLED = 'TRANSFER_CANCELLED';

    public const RELEASED = 'TRANSFER_RELEASED';

    public const PROOF_UPLOADED = 'PROOF_UPLOADED';

    public function record(
        Transaction $transfer,
        string $action,
        ?User $actor = null,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        ?string $comment = null,
        array $metadata = [],
    ): TransferAuditLog {
        return TransferAuditLog::create([
            'transfer_id' => $transfer->id,
            'user_id' => $actor?->id,
            'role' => $actor?->role,
            'action' => $action,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'comment' => $comment,
            'metadata' => $metadata ?: null,
        ]);
    }
}
