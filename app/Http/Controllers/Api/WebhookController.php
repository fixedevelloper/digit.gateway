<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\TransactionStatusUpdater;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Réceptionne les notifications de statut transactionnel envoyées par Digitwave
     * (event 'transaction_updated'). La signature a déjà été vérifiée par le
     * middleware VerifyDigitwaveSignature avant d'atteindre ce contrôleur.
     *
     * Remplace le polling de `transaction:status` comme chemin nominal de mise à
     * jour (le cron reste un filet de sécurité en fallback) ; réutilise le même
     * service (TransactionStatusUpdater) pour ne jamais diverger de sa logique.
     *
     * Répond toujours 200 dès que le payload est reconnu (même transaction
     * introuvable ou déjà finalisée) pour éviter que Digitwave ne rejoue
     * indéfiniment une notification qu'on ne pourra jamais traiter différemment.
     */
    public function digitwave(Request $request, TransactionStatusUpdater $updater)
    {
        $requestId = $request->input('data.request_id');
        $status = $request->input('data.status');

        Log::info('[Webhook Digitwave] Notification reçue.', [
            'event' => $request->input('event'),
            'request_id' => $requestId,
            'status' => $status,
        ]);

        if (! $requestId || ! $status) {
            Log::warning('[Webhook Digitwave] Payload invalide : data.request_id ou data.status manquant.', [
                'payload' => $request->all(),
            ]);

            return response()->json(['status' => 'error', 'message' => 'Payload invalide.'], 422);
        }

        $transaction = Transaction::where('gateway_reference', $requestId)->first();

        if (! $transaction) {
            Log::warning("[Webhook Digitwave] Transaction introuvable pour gateway_reference={$requestId}.");

            return response()->json(['status' => 'ok']);
        }

        // 'transaction_sms' est le texte du SMS envoyé au destinataire, pas un message
        // d'erreur exploitable ; 'data.message' n'est pas documenté par Digitwave mais
        // repris défensivement au cas où un événement d'échec le fournirait un jour.
        $updater->apply($transaction, $status, $request->input('data.message'));

        return response()->json(['status' => 'ok']);
    }
}
