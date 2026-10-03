<?php

namespace App\Events;

class TransferCompleted extends TransferStatusEvent
{
    public function message(): string
    {
        return "Votre transfert {$this->transfer->reference} est terminé.";
    }
}
