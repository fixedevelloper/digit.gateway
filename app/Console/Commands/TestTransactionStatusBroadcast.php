<?php

namespace App\Console\Commands;

use App\Events\TransactionStatusUpdated;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestTransactionStatusBroadcast extends Command
{
    /**
     * php artisan transaction:test-broadcast TX-12345 success
     */
    protected $signature = 'transaction:test-broadcast
                            {reference : La référence de la transaction à simuler}
                            {status=success : Le statut à appliquer (success|failed|processing)}';

    protected $description = 'Simule une mise à jour de statut et diffuse l\'event Reverb, sans appeler la passerelle Digitwave (pour tester le flux temps réel côté Flutter)';

    public function handle(): int
    {
        $reference = $this->argument('reference');
        $status = $this->argument('status');

        $transaction = Transaction::where('reference', $reference)->first();

        if (!$transaction) {
            $this->error("❌ Aucune transaction trouvée avec la référence : {$reference}");
            return Command::FAILURE;
        }

        $this->info("🔧 Simulation : {$transaction->reference} → statut '{$status}'");

        // Mise à jour locale uniquement, aucun appel réseau vers Digitwave
        $transaction->update(['status' => $status]);

        // Diffusion réelle sur le canal Reverb (c'est ça que tu veux tester)
        try {
            TransactionStatusUpdated::dispatch($transaction);
            $this->info("✅ dispatch() exécuté sans exception");
        } catch (\Throwable $e) {
            $this->error("❌ Exception lors du dispatch : " . $e->getMessage());
            Log::error('[test-broadcast] Exception', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
        Log::info('[Broadcast Dispatch] Event TransactionStatusUpdated diffusé', [
            'channel'   => 'user.' . $transaction->user_id,
            'reference' => $transaction->reference,
            'status'    => $transaction->status,
        ]);
        $this->info("📡 Event 'TransactionStatusUpdated' diffusé sur le canal user.{$transaction->user_id}");

        return Command::SUCCESS;
    }
}
