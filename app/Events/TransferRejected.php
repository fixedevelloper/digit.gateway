<?php

namespace App\Events;

class TransferRejected extends TransferStatusEvent
{
    public function message(): string
    {
        return "Votre transfert {$this->transfer->reference} a été rejeté. Les fonds vous ont été restitués.";
    }
}
