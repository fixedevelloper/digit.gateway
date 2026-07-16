<?php

namespace App\Console\Commands;

use App\Services\DigitwaveService;
use Illuminate\Console\Command;
use App\Models\Transaction;
use Exception;

class CheckTransactionStatus extends Command
{
    /**
     * Signature de la commande en console.
     * Exemple : php artisan transaction:status
     *
     * @var string
     */
    protected $signature = 'transaction:status';

    /**
     * La description de la commande.
     *
     * @var string
     */
    protected $description = 'Vérifie et met à jour en masse le statut de toutes les transactions en attente ou en cours';

    /**
     * Exécuter la commande.
     *
     * @param DigitwaveService $digitwaveService
     * @return int
     */
    public function handle(DigitwaveService $digitwaveService): int
    {
        // 1. Récupérer les transactions éligibles (pending ou processing) ayant une référence passerelle
        // On traite en priorité les plus anciennes (oldest)
        $transactions = Transaction::whereIn('status', ['pending', 'processing'])
            ->whereNotNull('gateway_reference')
            ->where('gateway_reference', '!=', '')
            ->oldest()
            ->get();

        $count = $transactions->count();

        if ($count === 0) {
            $this->info("✅ Aucune transaction en cours ou en attente à vérifier.");
            return Command::SUCCESS;
        }

        $this->info("🔍 {$count} transaction(s) trouvée(s) à vérifier. Début de l'analyse...");
        $this->line("------------------------------------------------------------------");

        // Barre de progression dans la console
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        foreach ($transactions as $transaction) {
            try {
                // 2. Appel au service Digitwave via la référence passerelle
                $result = $digitwaveService->checkStatus($transaction->gateway_reference);

                logger()->info("[Cron Status Check] Analyse transaction : {$transaction->reference}", [
                    'gateway_ref' => $transaction->gateway_reference,
                    'result'      => $result
                ]);

                if (isset($result['success']) && $result['success'] === true) {
                    $apiData = $result['data'];

                    // Normalisation complète (en minuscules et sans espaces)
                    $apiStatus = strtolower(trim($apiData['status'] ?? 'pending'));

                    // 3. Mise à jour selon le statut réel de l'API Digitwave
                    if (in_array($apiStatus, ['success', 'successful', 'completed'])) {

                        $transaction->update([
                            'status' => 'success'
                        ]);

                    } elseif (in_array($apiStatus, ['failed', 'failure', 'rejected', 'declined'])) {

                        $transaction->update([
                            'status'         => 'failed',
                            'failure_reason' => $apiData['message'] ?? 'Rejeté par l\'opérateur (Cron Check)'
                        ]);

                        // Remboursement automatique si c'est un transfert initié (TX)
                        if (str_starts_with($transaction->reference, 'TX-')) {
                            $refundAmount = $transaction->amount_sent + $transaction->fees;

                            $wallet = $transaction->user->wallet;
                            $wallet->increment('balance', $refundAmount);

                            logger()->warning("[Cron Status Check] Transfert échoué {$transaction->reference}. Utilisateur remboursé de : {$refundAmount} XAF");
                        }
                    }
                    // Si le statut est "pending", "processing", ou "initiated", on ne fait rien pour la laisser tourner.
                } else {
                    logger()->error("[Cron Status Check] Échec de l'appel API pour la référence {$transaction->reference}", [
                        'response' => $result
                    ]);
                }

            } catch (Exception $e) {
                logger()->error("[Cron Status Check] Erreur critique sur la transaction {$transaction->reference} : " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("🏁 Traitement terminé ! Les transactions ont été mises à jour.");
        $this->line("------------------------------------------------------------------");

        return Command::SUCCESS;
    }
}
