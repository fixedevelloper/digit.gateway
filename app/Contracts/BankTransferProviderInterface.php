<?php

namespace App\Contracts;

use App\Services\Banking\TransferResult;

/**
 * Contrat d'un provider de transfert bancaire. Seul ManualBankTransferProvider existe
 * aujourd'hui ; un provider automatique s'ajoute en l'implémentant et en l'enregistrant
 * dans config/transfers.php (gateways.BANK_TRANSFER).
 */
interface BankTransferProviderInterface
{
    /**
     * @param  array<string, mixed>  $data  référence, montants/devises, pays et coordonnées bancaires du bénéficiaire
     */
    public function transfer(array $data): TransferResult;
}
