<?php

namespace App\Events;

class TransferProcessing extends TransferStatusEvent
{
    public function message(): string
    {
        return "Votre transfert {$this->transfer->reference} est en cours de traitement.";
    }
}
