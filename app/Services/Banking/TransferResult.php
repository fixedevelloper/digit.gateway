<?php

namespace App\Services\Banking;

use App\Models\Transaction;

/**
 * Résultat d'une demande de transfert bancaire auprès d'un provider.
 */
final class TransferResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $message = null,
    ) {}

    /** Aucun provider automatique : le transfert attend la prise en charge d'un agent. */
    public static function pendingManualReview(): self
    {
        return new self(true, Transaction::STATUS_PENDING_MANUAL_REVIEW);
    }

    public static function failure(string $message): self
    {
        return new self(false, 'failed', null, $message);
    }
}
