<?php

namespace App\Listeners;

use App\Events\TransferCreated;
use App\Models\User;
use App\Notifications\ManualTransferPendingNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Un nouveau transfert manuel entre dans la file : les agents actifs en sont informés.
 */
class NotifyAgentsOfManualTransfer implements ShouldQueue
{
    public int $tries = 3;

    public function handle(TransferCreated $event): void
    {
        if (! $event->transfer->isManual() || $event->transfer->environment !== 'production') {
            return;
        }

        Notification::send(
            User::where('role', 'agent')->where('status', true)->get(),
            new ManualTransferPendingNotification($event->transfer)
        );
    }
}
