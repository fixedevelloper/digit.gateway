<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTransferJob;
use Illuminate\Console\Command;
use App\Models\Transaction;
use App\Jobs\SendMoneyJob; // ⚠️ Remplace par le nom réel de ton Job

class TestDigitwaveCongo extends Command
{
    /**
     * Le nom et la signature de la commande (ce que tu tapes dans le terminal).
     * On passe la référence de la transaction en paramètre.
     *
     * @var string
     */
    protected $signature = 'test:digitwave-congo {reference : La référence de la transaction à tester}';

    /**
     * La description de la commande.
     *
     * @var string
     */
    protected $description = 'Force l\'exécution du Job Digitwave pour tester la redirection Congo sur une transaction spécifique';

    /**
     * Exécute la commande.
     */
    public function handle(): int
    {
        $reference = $this->argument('reference');

        // 1. Recherche de la transaction
        $transaction = Transaction::where('reference', $reference)->first();

        if (!$transaction) {
            $this->error("❌ Impossible de trouver la transaction avec la référence : {$reference}");
            return Command::FAILURE;
        }

        $this->info("🔍 Transaction trouvée !");
        $this->line("--------------------------------------------------");
        $this->line("ID      : " . $transaction->id);
        $this->line("Pays    : '" . $transaction->country_name . "'");
        $this->line("Statut  : '" . $transaction->status . "'");
        $this->line("Opérat. : '" . $transaction->recipient_operator . "'");
        $this->line("Téléph. : " . $transaction->recipient_phone);
        $this->line("Montant : " . $transaction->amount_to_receive);
        $this->line("--------------------------------------------------");

        // 2. Si tu veux tester le Job mais que le statut bloque (ex: elle n'est pas 'pending' ou 'processing')
        if (!in_array($transaction->status, ['pending', 'processing'])) {
            $this->warn("⚠️ Le statut actuel est '{$transaction->status}'. Le Job va normalement l'ignorer.");

            if ($this->confirm("Voulez-vous forcer temporairement le statut à 'pending' pour ce test ?", true)) {
                $transaction->update(['status' => 'pending']);
                $this->info("✅ Statut mis à jour à 'pending'.");
            } else {
                $this->error("❌ Test annulé car le statut bloque.");
                return Command::FAILURE;
            }
        }

        $this->info("🚀 Lancement du Job en mode synchrone...");

        try {
            // 3. Dispatch du Job en mode synchrone (exécuté immédiatement dans la console, pas en arrière-plan)
            dispatch_sync(new ProcessTransferJob($transaction)); // ⚠️ Ajuste le nom de ton Job si besoin

            $this->info("✅ Le Job s'est exécuté sans lever d'exception.");

            // Recharger la transaction pour voir si elle a changé de statut (ex: 'processing' ou 'success')
            $transaction->refresh();
            $this->info("Nouveau statut en BDD : '" . $transaction->status . "'");

        } catch (\Exception $e) {
            $this->error("💥 Une erreur est survenue lors de l'exécution du Job :");
            $this->error($e->getMessage());
        }

        $this->line("--------------------------------------------------");
        $this->info("💡 Consulte tes fichiers de logs (storage/logs/laravel.log) pour voir les détails !");

        return Command::SUCCESS;
    }
}
