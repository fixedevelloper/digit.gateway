<?php

namespace App\Events;

class TransferFailed extends TransferStatusEvent
{
    public function message(): string
    {
        return "Votre transfert {$this->transfer->reference} a échoué. Les fonds vous ont été restitués.";
    }
}
