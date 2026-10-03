<?php

namespace App\Services\Banking;

use App\Contracts\BankTransferProviderInterface;

/**
 * « Provider » du traitement manuel : n'appelle aucun service externe et place le
 * transfert dans la file des agents.
 */
class ManualBankTransferProvider implements BankTransferProviderInterface
{
    public function transfer(array $data): TransferResult
    {
        return TransferResult::pendingManualReview();
    }
}
