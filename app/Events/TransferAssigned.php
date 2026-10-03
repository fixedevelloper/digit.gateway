<?php

namespace App\Events;

class TransferAssigned extends TransferStatusEvent
{
    public function message(): string
    {
        return "Votre transfert {$this->transfer->reference} a été pris en charge.";
    }
}
