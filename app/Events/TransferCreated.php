<?php

namespace App\Events;

class TransferCreated extends TransferStatusEvent
{
    public function message(): string
    {
        return $this->transfer->isManual()
            ? "Votre transfert {$this->transfer->reference} a été créé et attend sa prise en charge."
            : "Votre transfert {$this->transfer->reference} a été créé.";
    }
}
