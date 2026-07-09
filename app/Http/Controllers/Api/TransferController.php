<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransferRequest;
use App\Http\Requests\WithdrawalRequest;
use App\Http\Requests\DepositRequest;
use App\Jobs\ProcessTransferJob;
use App\Jobs\ProcessWithdrawalJob;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth; // <- Ajouté pour l'authentification
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Agency; // Pensez à importer votre modèle d'agence

class TransferController extends Controller
{
    /**
     * POST /api/transfer
     * Gère l'envoi d'argent (Débit immédiat du solde local, envoi via API en arrière-plan)
     */
    public function initiateTransfer(TransferRequest $request)
    {
        // Récupération de l'utilisateur authentifié via Sanctum ou Session
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $wallet = $user->wallet;
        $amount = (float)$request->amount;
        $feeCharged = 2.00;
        $totalDeduction = $amount + $feeCharged;

        if ($wallet->balance < $totalDeduction) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Insufficient fund/Balance.'
            ], 400);
        }

        $requestId = 'TX-' . strtoupper(Str::random(12));

        try {
            DB::beginTransaction();

            $wallet->decrement('balance', $totalDeduction);

            $transaction = Transaction::create([
                'reference' => $requestId,
                'user_id' => $user->id,
                'recipient_phone' => $request->number,
                'recipient_operator' => $request->carrier,
                'amount_sent' => $amount,
                'country_name'=>$request->country,
                'currency_sent' => $wallet->currency,
                'fees' => $feeCharged,
                'amount_to_receive' => $amount,
                'currency_received' => 'XAF',
                'status' => 'processing',
                'type' => 'transfer',
            ]);

            DB::commit();

            ProcessTransferJob::dispatch($transaction);

            return response()->json([
                'status'            => 'success',
                'message'           => 'Request accepted, processing in progress',
                'amount'            => $amount,
                'fee_charged'       => $feeCharged,
                'total'             => $totalDeduction,
                'remaining_balance' => (float)$wallet->refresh()->balance,
                'request_id'        => $requestId
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            logger($e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'An error occurred while processing your request. Please try again.'
            ], 500);
        }
    }

    /**
     * POST /api/withdrawal
     * Gère la demande de retrait / collecte
     */


public function initiateWithdrawal(WithdrawalRequest $request)
{
    $user = Auth::user();

    if (!$user) {
        return response()->json([
            'status'  => 'error',
            'message' => 'Unauthenticated.'
        ], 401);
    }

    // 1. Vérification de l'existence et du statut de l'agence
/*    $agency = Agency::where('code', $request->agensic_code)
                    ->where('status', 'active') // Optionnel : s'assurer qu'elle n'est pas suspendue
                    ->first();*/
    $agency = Agency::where('status', 'active') // Optionnel : s'assurer qu'elle n'est pas suspendue
        ->first();

    if (!$agency) {
        return response()->json([
            'status'  => 'error',
            'message' => "Le code d'agence fourni est invalide ou l'agence n'est pas disponible."
        ], 422); // 422 Unprocessable Entity pour les erreurs de validation métier
    }

    $wallet = $user->wallet;
    $amount = (float) $request->amount;
    $feeCharged = 5.00;
    $totalDeduction = $amount + $feeCharged;

    // 2. [Sécurité Optionnelle] : Vérifier si le portefeuille a un solde suffisant
    if ($wallet->balance < $totalDeduction) {
        return response()->json([
            'status'  => 'error',
            'message' => 'Solde insuffisant pour effectuer ce retrait.'
        ], 400);
    }

    $requestId = 'WD-' . strtoupper(Str::random(12));

    try {
        DB::beginTransaction();

        // Déduire le montant du portefeuille de l'utilisateur ici si ce n'est pas géré dans le Job
        $wallet->decrement('balance', $totalDeduction);

        $transaction = Transaction::create([
            'reference' => $requestId,
            'user_id' => $user->id,
            'agency_id' => $agency->id, // Associer l'ID de l'agence trouvée
            'recipient_phone' => $request->number,
            'recipient_operator' => $request->carrier,
            'country_name' => $request->country,
            'amount_sent' => $amount,
            'fees' => $feeCharged,
            'amount_to_receive' => $amount,
            'currency_sent' => $wallet->currency,
            'currency_received' => $wallet->currency,
            'status' => 'pending',
            'type' => 'withdrawal', // Correction : 'withdrawal' au lieu de 'deposit'
        ]);

        DB::commit();

        ProcessTransferJob::dispatch($transaction);

        return response()->json([
            'status'            => 'success',
            'message'           => 'Request accepted, processing in progress',
            'amount'            => $amount,
            'fee_charged'       => $feeCharged,
            'total'             => $totalDeduction,
            'remaining_balance' => (float) $wallet->refresh()->balance, // refresh pour avoir le solde à jour
            'request_id'        => $requestId
        ], 200);

    } catch (\Exception $e) {
        DB::rollBack();

        // Loggez l'erreur pour le debug interne si nécessaire
        Log::error("Erreur retrait: " . $e->getMessage());

        return response()->json([
            'status'  => 'error',
            'message' => 'An error occurred during the withdrawal request.'
        ], 500);
    }
}

        /**
     * POST /api/deposit
     * Gère la demande de retrait / collecte
     */
    public function initiateDeposit(DepositRequest $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $wallet = $user->wallet;
        $amount = (float) $request->amount;
        $feeCharged = 5.00;
        $totalDeduction = $amount + $feeCharged;

        $requestId = 'WD-' . strtoupper(Str::random(12));

        try {
            DB::beginTransaction();

            $transaction = Transaction::create([
                'reference' => $requestId,
                'user_id' => $user->id,
                'recipient_phone' => $request->number,
                'recipient_operator' => $request->carrier,
                'country_name'=>$request->country,
                'amount_sent' => $amount,
                'fees' => $feeCharged,
                'amount_to_receive' => $totalDeduction,
                'currency_sent' => $wallet->currency,
                'currency_received' => $wallet->currency,
                'status' => 'pending',
                'type' => 'deposit',
            ]);

            DB::commit();

            ProcessWithdrawalJob::dispatch($transaction);

            return response()->json([
                'status'            => 'success',
                'message'           => 'Request accepted, processing in progress',
                'amount'            => $amount,
                'fee_charged'       => $feeCharged,
                'total'             => $totalDeduction,
                'remaining_balance' => (float) $wallet->balance,
                'request_id'        => $requestId
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => 'An error occurred during the withdrawal request.'
            ], 500);
        }
    }

    /**
     * GET /api/transactions
     * Liste des transactions récentes du marchand authentifié
     */
    public function recentTransactions(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Unauthenticated.'
                ], 401);
            }

            $transactions = Transaction::where('user_id', $user->id)
                ->latest()
                ->take(5)
                ->get();

            return response()->json([
                'status' => 'success',
                'data'   => $transactions
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la récupération des transactions.'
            ], 500);
        }
    }

    /**
     * GET /api/history?page=1&type=all
     * Historique complet filtré par marchand avec pagination
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function historyList(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Unauthenticated.'
                ], 401);
            }

            $type = $request->query('type', 'all');

            $query = Transaction::where('user_id', $user->id);

            if ($type !== 'all') {
                $query->where('type', $type);
            }

            $transactions = $query->latest()->paginate(15);

            return response()->json([
                'status' => 'success',
                'type'   => $type,
                'data'   => $transactions
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Impossible de charger l\'historique.'
            ], 500);
        }
    }
    /**
     * Récupérer le statut actuel d'une transaction pour le polling Flutter.
     *
     * URL: GET /api/transactions/{id}/status
     */
    public function getTransactionStatus(string $id)
    {
        try {
            // 1. Recherche de la transaction en base de données
            // On cherche par l'identifiant unique (reference, request_id ou id technique)
            $transaction = Transaction::where('reference', $id)
                ->orWhere('id', $id)
                ->first();

            if (!$transaction) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Transaction introuvable.'
                ], 404);
            }

            // 2. Retour de la réponse structurée pour le WaitingScreen de Flutter
            return response()->json([
                'status'  => $transaction->status, // 'success', 'pending', ou 'failed'
                'message' => $transaction->failure_reason ?? 'Statut de la transaction récupéré.',
                'data'    => [
                    'id'         => $transaction->id,
                    'request_id' => $transaction->request_id,
                    'amount'     => $transaction->amount,
                    'number'     => $transaction->recipient_number,
                    'carrier'    => $transaction->carrier,
                    'updated_at' => $transaction->updated_at->toIso8601String(),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'failed',
                'message' => 'Erreur lors de la vérification du statut : ' . $e->getMessage()
            ], 500);
        }
    }
}
