<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\KybGraceReminderNotification;
use Illuminate\Console\Command;

/**
 * Rappelle aux marchands déjà en production de compléter leur dossier KYB avant la fin du délai de grâce.
 *
 * Paliers : J-14, J-7, J-3, J-1, puis le jour de l'échéance (délai dépassé). Chaque palier n'est envoyé qu'une
 * fois (`kyb_reminder_stage`), et si le planificateur a manqué des jours, seul le palier le plus avancé part.
 * Aucun rappel pour un dossier approuvé, déjà soumis (en cours d'examen), ou un compte suspendu.
 * Ce rappel ne suspend rien : la suite à donner à un délai dépassé reste une décision de l'équipe.
 */
class RemindKybGrace extends Command
{
    /** Palier → nombre de jours restants à partir duquel il est envoyé. */
    public const STAGES = [1 => 14, 2 => 7, 3 => 3, 4 => 1, 5 => 0];

    protected $signature = 'kyb:remind-grace';

    protected $description = 'Rappelle aux marchands en production de compléter leur dossier de vérification avant l\'échéance';

    public function handle(): int
    {
        $sent = 0;
        $overdue = 0;

        User::where('role', 'merchant')
            ->where('environment', 'production')
            ->where('status', true)
            ->whereNotNull('kyb_grace_until')
            ->whereIn('kyb_status', ['incomplete', 'rejected'])
            ->chunkById(100, function ($merchants) use (&$sent, &$overdue) {
                foreach ($merchants as $merchant) {
                    $daysLeft = (int) floor(now()->startOfDay()->diffInDays($merchant->kyb_grace_until->copy()->startOfDay(), false));
                    $stage = $this->stageFor($daysLeft);

                    if ($daysLeft <= 0) {
                        $overdue++;
                    }

                    if ($stage > $merchant->kyb_reminder_stage) {
                        $merchant->notify(new KybGraceReminderNotification($daysLeft, $merchant->kyb_grace_until));
                        $merchant->forceFill(['kyb_reminder_stage' => $stage])->save();
                        $sent++;
                    }
                }
            });

        if ($overdue > 0) {
            logger()->warning("[KYB] {$overdue} marchand(s) en production ont dépassé leur délai de régularisation.");
        }

        $this->info("{$sent} rappel(s) envoyé(s), {$overdue} marchand(s) en retard.");

        return self::SUCCESS;
    }

    private function stageFor(int $daysLeft): int
    {
        $stage = 0;

        foreach (self::STAGES as $number => $threshold) {
            if ($daysLeft <= $threshold) {
                $stage = $number;
            }
        }

        return $stage;
    }
}
