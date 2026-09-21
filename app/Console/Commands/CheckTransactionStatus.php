<?php

namespace App\Console\Commands;

use App\Contracts\PaymentGatewayContract;
use App\Models\Transaction;
use App\Services\TransactionStatusUpdater;
use Exception;
use Illuminate\Console\Command;

class CheckTransactionStatus extends Command
{
    protected $signature = 'transaction:status';

    protected $description = 'Vérifie et met à jour en masse le statut des transactions';

    public function handle(PaymentGatewayContract $gateway, TransactionStatusUpdater $updater): int
    {
        $query = Transaction::whereIn('status', ['pending', 'processing'])
            ->whereNotNull('gateway_reference')
            ->where('gateway_reference', '!=', '');

        $count = $query->count();

        if ($count === 0) {
            $this->info('✅ Aucune transaction à vérifier.');

            return Command::SUCCESS;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        // Traitement par lots pour la performance
        $query->chunkById(50, function ($transactions) use ($gateway, $updater, $bar) {
            foreach ($transactions as $transaction) {
                try {
                    $result = $gateway->checkStatus($transaction->gateway_reference);

                    if ($result->success) {
                        $updater->apply($transaction, $result->status ?? 'pending', $result->message);
                    }
                } catch (Exception $e) {
                    logger()->error("[Cron Status Check] Erreur : {$transaction->reference} - ".$e->getMessage());
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info('🏁 Traitement terminé.');

        return Command::SUCCESS;
    }
}
