<?php

namespace App\Console\Commands;

use App\Events\TransactionStatusUpdated;
use App\Services\DigitwaveService;
use Illuminate\Console\Command;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Exception;

class CheckTransactionStatus extends Command
{
    protected $signature = 'transaction:status';
    protected $description = 'Vérifie et met à jour en masse le statut des transactions';

    public function handle(DigitwaveService $digitwaveService): int
    {
        $query = Transaction::whereIn('status', ['pending', 'processing'])
            ->whereNotNull('gateway_reference')
            ->where('gateway_reference', '!=', '');

        $count = $query->count();

        if ($count === 0) {
            $this->info("✅ Aucune transaction à vérifier.");
            return Command::SUCCESS;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        // Traitement par lots pour la performance
        $query->chunkById(50, function ($transactions) use ($digitwaveService, $bar) {
            foreach ($transactions as $transaction) {
                try {
                    $result = $digitwaveService->checkStatus($transaction->gateway_reference);

                    if (isset($result['success']) && $result['success'] === true) {
                        $apiStatus = strtolower(trim($result['data']['status'] ?? 'pending'));

                        // Utilisation d'une transaction DB pour l'intégrité des données
                        DB::transaction(function () use ($transaction, $apiStatus, $result) {
                            // Recharger pour verrouiller la ligne et vérifier l'état actuel
                            $transaction = Transaction::where('id', $transaction->id)->lockForUpdate()->first();
                            
                            if (!in_array($transaction->status, ['pending', 'processing'])) {
                                return;
                            }

                            if (in_array($apiStatus, ['success', 'successful', 'completed'])) {
                                $transaction->update(['status' => 'success']);
                                
                                // Créditer uniquement si c'est un dépôt
                                if ($transaction->type === 'deposit') {
                                    $transaction->user->wallet()->increment('balance', $transaction->amount_sent);
                                }
                            } 
                            elseif (in_array($apiStatus, ['failed', 'failure', 'rejected', 'declined'])) {
                                $transaction->update([
                                    'status'         => 'failed',
                                    'failure_reason' => $result['data']['message'] ?? 'Rejeté par l\'opérateur'
                                ]);

                                // Remboursement automatique
                                if (str_starts_with($transaction->reference, 'TX-')) {
                                    $refundAmount = $transaction->amount_sent + $transaction->fees;
                                    $transaction->user->wallet()->increment('balance', $refundAmount);
                                }
                            }
                        });

                        TransactionStatusUpdated::dispatch($transaction);
                    }
                } catch (Exception $e) {
                    logger()->error("[Cron Status Check] Erreur : {$transaction->reference} - " . $e->getMessage());
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("🏁 Traitement terminé.");
        return Command::SUCCESS;
    }
}