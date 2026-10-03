<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Base des événements de cycle de vie d'un transfert. Dispatchés après le commit de la
 * transaction DB qui a changé le statut (jamais à l'intérieur) ; l'audit, lui, est écrit
 * dans cette même transaction pour rester atomique avec le changement d'état.
 */
abstract class TransferStatusEvent implements TransferEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(public Transaction $transfer) {}

    public function transfer(): Transaction
    {
        return $this->transfer;
    }
}
