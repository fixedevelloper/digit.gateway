<?php

namespace App\Console\Commands;

use App\Services\DigitwaveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

class CheckTransactionStatus extends Command
{
    /**
     * Le nom et la signature de la commande en console.
     * Exemple : php artisan transaction:status WD-12345
     */
    protected $signature = 'transaction:status {request_id : L\'identifiant unique de la requête}';

    /**
     * La description de la commande.
     */
    protected $description = 'Vérifie et met à jour le statut d\'une transaction via l\'API externe';

    /**
     * Exécuter la commande.
     */
    public function handle(DigitwaveService $digitwaveService)
    {
        $requestId = $this->argument('request_id');

        // 1. Trouver la transaction localement
        $transaction = Transaction::where('reference', $requestId)->first();

        if (!$transaction) {
            $this->error("La transaction avec la référence {$requestId} n'existe pas en base de données.");
            return Command::FAILURE;
        }

        $this->info("Vérification du statut pour la requête : {$requestId}...");

        // 2. Appel GET à l'API externe avec le Query Mapping

        $result=$digitwaveService->checkStatus($transaction->gateway_reference);




        logger($result);
        if (isset($result['success']) && $result['success'] === true) {
            $apiData = $result['data'];
            $apiStatus = strtolower($apiData['status']); // "success", "pending", "failed"

            // 3. Mise à jour de la base de données selon le statut retourné
            if ($apiStatus === 'success') {
                $transaction->update(['status' => 'success']);
                $this->info("Statut mis à jour avec succès : SUCCESS");

            } elseif ($apiStatus === 'failed') {
                $transaction->update(['status' => 'failed']);

                // Remboursement si c'est un envoi (TX)
                if (str_starts_with($transaction->reference, 'TX-')) {
                    $refundAmount = $transaction->amount_sent + $transaction->fees;
                    $transaction->user->wallet->increment('balance', $refundAmount);
                }

                $this->warn("La transaction a échoué côté opérateur. Utilisateur traité.");
            } else {
                $this->line("La transaction est toujours en attente (Pending)...");
            }

            return Command::SUCCESS;
        }

        $this->error("L'API a retourné un échec de recherche.");
        return Command::FAILURE;
    }
}
