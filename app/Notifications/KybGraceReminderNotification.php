<?php

namespace App\Notifications;

use App\Notifications\Concerns\HasOrderedId;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Rappel adressé aux marchands déjà en production dont le dossier de vérification (KYB) n'est pas approuvé :
 * il reste N jours avant la fin du délai de régularisation (ou le délai est dépassé). Portail + e-mail, en file.
 */
class KybGraceReminderNotification extends Notification implements ShouldQueue
{
    use HasOrderedId, Queueable;

    public function __construct(private readonly int $daysLeft, private readonly CarbonInterface $deadline)
    {
        $this->useOrderedId();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    private function overdue(): bool
    {
        return $this->daysLeft <= 0;
    }

    private function message(): string
    {
        $date = $this->deadline->translatedFormat('d/m/Y');

        return match (true) {
            $this->overdue() => "Le délai pour compléter votre dossier de vérification est dépassé (échéance : {$date}). Complétez-le dès maintenant : l'accès de votre compte à la production va être réexaminé.",
            $this->daysLeft === 1 => "Dernier jour demain : complétez votre dossier de vérification avant le {$date}.",
            default => "Il vous reste {$this->daysLeft} jours pour compléter votre dossier de vérification (échéance : {$date}).",
        };
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'kyb.grace_reminder',
            'days_left' => max(0, $this->daysLeft),
            'overdue' => $this->overdue(),
            'deadline' => $this->deadline->toDateString(),
            'message' => $this->message(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->overdue() ? 'Dossier de vérification : délai dépassé' : "Dossier de vérification : {$this->daysLeft} jour(s) restant(s)")
            ->greeting('Bonjour '.($notifiable->name ?: $notifiable->company_name ?: '').',')
            ->line($this->message())
            ->line('Il suffit de renseigner les informations de votre entreprise et de déposer les pièces demandées depuis votre portail, puis de soumettre le dossier.');

        if ($link = config('app.frontend_url')) {
            $mail->action('Compléter mon dossier', rtrim($link, '/').'/portal/dashboard/kyb');
        }

        return $mail->salutation('— '.config('app.name'));
    }
}
