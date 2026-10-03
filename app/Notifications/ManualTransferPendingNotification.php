<?php

namespace App\Notifications;

use App\Models\Transaction;
use Illuminate\Notifications\Notification;

/**
 * Prévient les agents qu'un nouveau transfert attend dans la file manuelle.
 */
class ManualTransferPendingNotification extends Notification
{
    public function __construct(private readonly Transaction $transfer) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'transfer.manual_pending',
            'transfer_id' => $this->transfer->id,
            'reference' => $this->transfer->reference,
            'service' => $this->transfer->service->value,
            'message' => "Nouveau transfert à traiter : {$this->transfer->reference}.",
        ];
    }
}
