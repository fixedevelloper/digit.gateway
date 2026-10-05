<?php

namespace App\Console\Commands;

use App\Contracts\PaymentGatewayContract;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\ReconciliationRequiredNotification;
use App\Services\ReconciliationService;
use App\Services\TransactionStatusUpdater;
use Illuminate\Console\Command;
use Throwable;

class ReconcileTransactions extends Command
{
    protected $signature = 'transaction:reconcile';

    protected $description = 'Re-vérifie les transactions bloquées chez Digitwave et alerte les admins pour celles à rapprocher à la main';

    public function handle(ReconciliationService $reconciliation, PaymentGatewayContract $gateway, TransactionStatusUpdater $updater): int
    {
        // 1. Transactions "stale" avec référence : Digitwave peut nous donner le statut.
        $reconciliation->query()
            ->whereNotNull('gateway_reference')->where('gateway_reference', '!=', '')
            ->chunkById(50, function ($transactions) use ($gateway, $updater) {
                foreach ($transactions as $transaction) {
                    try {
                        $result = $gateway->checkStatus($transaction->gateway_reference);

                        if ($result->success) {
                            $updater->apply($transaction, $result->status ?? 'pending', $result->message);
                        }
                    } catch (Throwable $e) {
                        logger()->error("[Rapprochement] {$transaction->reference} : ".$e->getMessage());
                    }
                }
            });

        // 2. Ce qui reste (re-évalué) et n'a pas encore été signalé : alerte unique.
        $toFlag = $reconciliation->query()->whereNull('reconciliation_flagged_at')->get();

        if ($toFlag->isEmpty()) {
            $this->info('Aucun nouveau rapprochement à signaler.');

            return self::SUCCESS;
        }

        foreach ($toFlag as $transaction) {
            logger()->critical("[RAPPROCHEMENT REQUIS] {$transaction->reference} ({$reconciliation->reasonFor($transaction)}).");
        }

        $notification = new ReconciliationRequiredNotification($toFlag->count(), $toFlag->pluck('reference')->all());
        User::whereIn('role', ['admin', 'superadmin'])->where('status', true)->get()
            ->each(fn (User $admin) => $admin->notify($notification));

        Transaction::whereIn('id', $toFlag->pluck('id'))->update(['reconciliation_flagged_at' => now()]);

        $this->warn("{$toFlag->count()} transaction(s) à rapprocher.");

        return self::SUCCESS;
    }
}
