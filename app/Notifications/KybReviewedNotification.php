<?php

namespace App\Notifications;

use App\Notifications\Concerns\HasOrderedId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Résultat de l'examen du dossier marchand (pièce refusée, dossier approuvé ou refusé) : notification dans le
 * portail ET e-mail. Mise en file : un serveur de messagerie indisponible n'empêche jamais l'équipe d'enregistrer
 * sa décision (l'envoi échoue alors dans les jobs en échec, surveillés par `monitor:check`).
 */
class KybReviewedNotification extends Notification implements ShouldQueue
{
    use HasOrderedId, Queueable;

    public function __construct(private readonly string $outcome, private readonly ?string $document = null, private readonly ?string $reason = null)
    {
        $this->useOrderedId();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(match ($this->outcome) {
                'approved' => 'Votre dossier est approuvé',
                'added_by_team' => 'Une pièce a été ajoutée à votre dossier',
                'document_rejected' => 'Une pièce de votre dossier a été refusée',
                default => 'Votre dossier de vérification a été refusé',
            })
            ->greeting('Bonjour '.($notifiable->name ?: $notifiable->company_name ?: '').',');

        match ($this->outcome) {
            'added_by_team' => $mail->line("Notre équipe a ajouté la pièce « {$this->document} » à votre dossier de vérification, à partir des documents que vous nous avez transmis.")
                ->line('Elle va maintenant être examinée. Vous pouvez consulter votre dossier depuis votre portail.'),
            'approved' => $mail->line('Bonne nouvelle : votre dossier de vérification est approuvé.')
                ->line('Notre équipe peut maintenant activer votre compte en production ; vous serez prévenu dès que ce sera fait.'),
            'document_rejected' => $mail->line("La pièce « {$this->document} » n'a pas pu être validée.")
                ->line("Motif : {$this->reason}")
                ->line('Déposez une nouvelle version, puis soumettez à nouveau votre dossier.'),
            default => $mail->line('Après examen, votre dossier de vérification n\'a pas pu être validé.')
                ->line("Motif : {$this->reason}")
                ->line('Corrigez-le, puis soumettez-le à nouveau.'),
        };

        $link = config('app.frontend_url');

        if (! in_array($this->outcome, ['approved'], true) && $link) {
            $mail->action('Ouvrir mon dossier', rtrim($link, '/').'/portal/dashboard/kyb');
        }

        return $mail->salutation('— '.config('app.name'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'kyb.reviewed',
            'outcome' => $this->outcome,
            'message' => match ($this->outcome) {
                'approved' => 'Votre dossier est approuvé : votre compte peut maintenant être activé en production.',
                'added_by_team' => "Notre équipe a ajouté la pièce « {$this->document} » à votre dossier : elle va être examinée.",
                'document_rejected' => "La pièce « {$this->document} » a été refusée : {$this->reason}. Déposez-en une nouvelle puis soumettez à nouveau votre dossier.",
                default => "Votre dossier a été refusé : {$this->reason}. Corrigez-le puis soumettez-le à nouveau.",
            },
        ];
    }
}
