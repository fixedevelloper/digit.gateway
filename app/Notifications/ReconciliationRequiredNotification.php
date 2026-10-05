<?php

namespace App\Notifications;

use App\Notifications\Concerns\HasOrderedId;
use Illuminate\Notifications\Notification;

/**
 * Prévient les admins que des transactions attendent un rapprochement manuel.
 */
class ReconciliationRequiredNotification extends Notification
{
    use HasOrderedId;

    public function __construct(private readonly int $count, private readonly array $references)
    {
        $this->useOrderedId();
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'reconciliation.required',
            'count' => $this->count,
            'references' => array_slice($this->references, 0, 10),
            'message' => "{$this->count} transaction(s) à rapprocher avec Digitwave.",
        ];
    }
}
