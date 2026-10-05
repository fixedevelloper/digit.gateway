<?php

namespace App\Notifications;

use App\Models\WalletAdjustment;
use Illuminate\Notifications\Notification;

/** Informe le demandeur du résultat de sa demande d'ajustement. */
class AdjustmentReviewedNotification extends Notification
{
    public function __construct(private readonly WalletAdjustment $adjustment) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $approved = $this->adjustment->status === WalletAdjustment::APPROVED;

        return [
            'type' => 'wallet_adjustment.reviewed',
            'adjustment_id' => $this->adjustment->id,
            'status' => $this->adjustment->status,
            'message' => $approved
                ? "Votre ajustement #{$this->adjustment->id} a été approuvé et appliqué."
                : "Votre ajustement #{$this->adjustment->id} a été refusé : {$this->adjustment->rejection_reason}",
        ];
    }
}
