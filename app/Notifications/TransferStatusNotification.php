<?php

namespace App\Notifications;

use App\Events\TransferEvent;
use Illuminate\Notifications\Notification;

/**
 * Notification client à chaque étape d'un transfert (créé, pris en charge, en traitement,
 * terminé, rejeté, échoué). Canal base de données, consultable via GET /notifications.
 */
class TransferStatusNotification extends Notification
{
    public function __construct(private readonly TransferEvent $event) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $transfer = $this->event->transfer();

        return [
            'type' => 'transfer.status',
            'transfer_id' => $transfer->id,
            'reference' => $transfer->reference,
            'status' => $transfer->status,
            'event' => class_basename($this->event),
            'message' => $this->event->message(),
            'amount' => (float) $transfer->amount_sent,
            'currency' => $transfer->currency_sent,
        ];
    }
}
