<?php

namespace App\Notifications;

use App\Models\KycSubmission;
use Illuminate\Notifications\Notification;

/**
 * Résultat d'une demande KYC, consultable via GET /notifications.
 */
class KycReviewedNotification extends Notification
{
    public function __construct(private readonly KycSubmission $submission) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $approved = $this->submission->status === KycSubmission::APPROVED;

        return [
            'type' => 'kyc.reviewed',
            'submission_id' => $this->submission->id,
            'status' => $this->submission->status,
            'level' => $this->submission->target_level,
            'message' => $approved
                ? "Votre identité est vérifiée : niveau {$this->submission->target_level} activé, vos plafonds ont été relevés."
                : 'Votre demande de vérification a été refusée : '.$this->submission->rejection_reason,
        ];
    }
}
