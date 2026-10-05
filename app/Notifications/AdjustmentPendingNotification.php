<?php

namespace App\Notifications;

use App\Models\WalletAdjustment;
use Illuminate\Notifications\Notification;

/** Prévient les superadmins qu'un ajustement de wallet attend leur validation. */
class AdjustmentPendingNotification extends Notification
{
    public function __construct(private readonly WalletAdjustment $adjustment) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'wallet_adjustment.pending',
            'adjustment_id' => $this->adjustment->id,
            'message' => "Ajustement de wallet à valider : {$this->adjustment->type} de {$this->adjustment->amount}.",
        ];
    }
}
