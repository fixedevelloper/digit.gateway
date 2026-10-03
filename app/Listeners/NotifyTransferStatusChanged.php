<?php

namespace App\Listeners;

use App\Events\TransferEvent;
use App\Notifications\TransferStatusNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Notifie le client à chaque changement important de son transfert. Placé en queue : une
 * indisponibilité du canal de notification ne doit jamais affecter le transfert lui-même.
 * Les notifications ne concernent que l'app mobile (pas les intégrations marchandes) et
 * jamais le sandbox.
 */
class NotifyTransferStatusChanged implements ShouldQueue
{
    public int $tries = 3;

    public function handle(TransferEvent $event): void
    {
        $transfer = $event->transfer();

        if ($transfer->channel !== 'mobile_app' || $transfer->environment !== 'production') {
            return;
        }

        $transfer->user->notify(new TransferStatusNotification($event));
    }
}
