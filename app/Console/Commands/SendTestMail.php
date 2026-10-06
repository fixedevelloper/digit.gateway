<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Vérifie la configuration e-mail de bout en bout : affiche le serveur utilisé (sans le mot de passe) et envoie
 * un message de test. À lancer après avoir renseigné MAIL_* dans api.env.
 */
class SendTestMail extends Command
{
    protected $signature = 'mail:test {to : Adresse destinataire du message de test}';

    protected $description = 'Envoie un e-mail de test pour vérifier la configuration SMTP';

    public function handle(): int
    {
        $to = (string) $this->argument('to');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("« {$to} » n'est pas une adresse e-mail valide.");

            return self::INVALID;
        }

        $mailer = config('mail.default');
        $smtp = config('mail.mailers.smtp');

        $this->line("Mailer       : {$mailer}");
        if ($mailer === 'smtp') {
            $this->line("Serveur      : {$smtp['host']}:{$smtp['port']} (".($smtp['scheme'] ?? 'auto').')');
            $this->line('Utilisateur  : '.($smtp['username'] ?: '(aucun)'));
        }
        $this->line('Expéditeur   : '.config('mail.from.address').' ('.config('mail.from.name').')');

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn("MAIL_MAILER={$mailer} : rien ne sera réellement envoyé (écriture dans les logs seulement).");
        }

        try {
            Mail::raw(
                "Ceci est un message de test envoyé par ".config('app.name')." le ".now()->format('d/m/Y à H:i').".\nSi vous le lisez, la configuration e-mail fonctionne.",
                fn ($m) => $m->to($to)->subject('Test d\'envoi — '.config('app.name'))
            );
        } catch (Throwable $e) {
            $this->error('Échec de l\'envoi : '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Message envoyé à {$to}. Vérifiez la boîte de réception (et les courriers indésirables).");

        return self::SUCCESS;
    }
}
