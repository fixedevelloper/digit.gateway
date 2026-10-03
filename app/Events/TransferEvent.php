<?php

namespace App\Events;

use App\Models\Transaction;

/**
 * Contrat commun des événements de cycle de vie d'un transfert. Les listeners s'y abonnent
 * via cette interface (Laravel ne propage pas les listeners d'une classe parente).
 */
interface TransferEvent
{
    public function transfer(): Transaction;

    /** Message affiché au client (notification). */
    public function message(): string;
}
