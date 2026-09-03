<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DepositRequest;
use App\Http\Requests\TransferRequest;
use App\Http\Requests\WithdrawalRequest;
use App\Jobs\ProcessTransferJob;
use App\Jobs\ProcessWithdrawalJob;
use App\Models\Agency;
use App\Models\Operator;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse; // <- Ajouté pour l'authentification
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

 // Pensez à importer votre modèle d'agence

class TransferController extends Controller
{
    /**
     * POST /api/transfer
     * Gère l'envoi d'argent (Débit immédiat du solde local, envoi via API en arrière-plan)
     */
    public function initiateTransfer(TransferRequest $request)
    {
        // Authentification (auth:sanctum) + PIN ('pin.verify') + anti-doublon ('idempotent:transfer')
        // sont déjà garantis par les middlewares de la route à ce stade.
        $user = Auth::user();

        $amount = (float) $request->amount;
        $requestId = 'TX-'.strtoupper(Str::random(12));

        try {
            // Verrouillage pessimiste de la ligne wallet pendant toute la transaction
            // pour empêcher une double dépense en cas de requêtes concurrentes.
            $result = DB::transaction(function () use ($user, $amount, $requestId, $request) {
                $operator = $this->resolveOperator($request->country, $request->carrier, $amount);
                $feeCharged = $this->computeOperatorFee($operator, $amount);
                $totalDeduction = $amount + $feeCharged;

                $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

                if ($wallet->balance < $totalDeduction) {
                    throw ValidationException::withMessages([
                        'amount' => ['Insufficient fund/Balance.'],
                    ]);
                }

                $wallet->decrement('balance', $totalDeduction);

                $transaction = Transaction::create([
                    'reference' => $requestId,
                    'user_id' => $user->id,
                    'recipient_phone' => $request->number,
                    'recipient_operator' => $request->carrier,
                    'amount_sent' => $amount,
                    'country_name' => $request->country,
                    'currency_sent' => $wallet->currency,
                    'fees' => $feeCharged,
                    'amount_to_receive' => $amount,
                    'currency_received' => 'XAF',
                    'status' => 'processing',
                    'type' => 'transfer',
                ]);

                return ['transaction' => $transaction, 'balance' => $wallet->balance, 'fee' => $feeCharged, 'total' => $totalDeduction];
            });
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first(),
            ], 400);
        } catch (\Exception $e) {
            logger($e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while processing your request. Please try again.',
            ], 500);
        }

        ProcessTransferJob::dispatch($result['transaction']);

        return response()->json([
            'status' => 'success',
            'message' => 'Request accepted, processing in progress',
            'amount' => $amount,
            'fee_charged' => $result['fee'],
            'total' => $result['total'],
            'remaining_balance' => (float) $result['balance'],
            'request_id' => $requestId,
        ], 200);
    }

    /**
     * POST /api/withdrawal
     * Gère la demande de retrait / collecte
     */
    public function initiateWithdrawal(WithdrawalRequest $request)
    {
        // Authentification (auth:sanctum) + PIN ('pin.verify') + anti-doublon ('idempotent:withdrawal')
        // sont déjà garantis par les middlewares de la route à ce stade.
        $user = Auth::user();

        // 1. Vérification de l'existence et du statut de l'agence via son code
        $agency = Agency::where('code', $request->agensic_code)
            ->where('status', 'active') // Optionnel : s'assurer qu'elle n'est pas suspendue
            ->first();

        if (! $agency) {
            return response()->json([
                'status' => 'error',
                'message' => "Le code d'agence fourni est invalide ou l'agence n'est pas disponible.",
            ], 422); // 422 Unprocessable Entity pour les erreurs de validation métier
        }

        $amount = (float) $request->amount;
        $requestId = 'WD-'.strtoupper(Str::random(12));

        try {
            // Verrouillage pessimiste de la ligne wallet pendant toute la transaction
            // pour empêcher une double dépense en cas de requêtes concurrentes.
            $result = DB::transaction(function () use ($user, $agency, $amount, $requestId, $request) {
                $operator = $this->resolveOperator($request->country, $request->carrier, $amount);
                $feeCharged = $this->computeOperatorFee($operator, $amount);
                $totalDeduction = $amount + $feeCharged;

                $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

                if ($wallet->balance < $totalDeduction) {
                    throw ValidationException::withMessages([
                        'amount' => ['Solde insuffisant pour effectuer ce retrait.'],
                    ]);
                }

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

                return ['transaction' => $transaction, 'balance' => $wallet->balance, 'fee' => $feeCharged, 'total' => $totalDeduction];
            });
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first(),
            ], 400);
        } catch (\Exception $e) {
            // Loggez l'erreur pour le debug interne si nécessaire
            Log::error('Erreur retrait: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred during the withdrawal request.',
            ], 500);
        }

        ProcessTransferJob::dispatch($result['transaction']);

        return response()->json([
            'status' => 'success',
            'message' => 'Request accepted, processing in progress',
            'amount' => $amount,
            'fee_charged' => $result['fee'],
            'total' => $result['total'],
            'remaining_balance' => (float) $result['balance'],
            'request_id' => $requestId,
        ], 200);
    }

    /**
     * POST /api/deposit
     * Gère la demande de retrait / collecte
     */
    public function initiateDeposit(DepositRequest $request)
    {
        // Authentification (auth:sanctum) + PIN ('pin.verify') + anti-doublon ('idempotent:deposit')
        // sont déjà garantis par les middlewares de la route à ce stade.
        $user = Auth::user();

        $wallet = $user->wallet;
        $amount = (float) $request->amount;
        $requestId = 'WD-'.strtoupper(Str::random(12));

        try {
            $operator = $this->resolveOperator($request->country, $request->carrier, $amount);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first(),
            ], 400);
        }

        $feeCharged = $this->computeOperatorFee($operator, $amount);
        $totalDeduction = $amount + $feeCharged;

        try {
            DB::beginTransaction();

            $transaction = Transaction::create([
                'reference' => $requestId,
                'user_id' => $user->id,
                'recipient_phone' => $request->number,
                'recipient_operator' => $request->carrier,
                'country_name' => $request->country,
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
                'status' => 'success',
                'message' => 'Request accepted, processing in progress',
                'amount' => $amount,
                'fee_charged' => $feeCharged,
                'total' => $totalDeduction,
                'remaining_balance' => (float) $wallet->balance,
                'request_id' => $requestId,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred during the withdrawal request.',
            ], 500);
        }
    }

    /**
     * Résout l'opérateur actif correspondant au pays et au code transmis par le client,
     * et vérifie que le montant demandé respecte les bornes min/max configurées pour cet opérateur.
     * Lève une ValidationException si l'opérateur est introuvable/inactif ou si le montant est hors bornes.
     */
    private function resolveOperator(string $countryName, string $carrierCode, float $amount): Operator
    {
        $operator = Operator::whereHas('country', function ($q) use ($countryName) {
            $q->where('name', $countryName)->where('status', true);
        })
            ->where('code', $carrierCode)
            ->where('status', true)
            ->first();

        if (! $operator) {
            throw ValidationException::withMessages([
                'carrier' => ["Opérateur « {$carrierCode} » non supporté ou indisponible pour « {$countryName} »."],
            ]);
        }

        if ($amount < (float) $operator->min_amount || $amount > (float) $operator->max_amount) {
            throw ValidationException::withMessages([
                'amount' => ["Le montant doit être compris entre {$operator->min_amount} et {$operator->max_amount} pour cet opérateur."],
            ]);
        }

        return $operator;
    }

    /**
     * Calcule le frais réel configuré pour l'opérateur (frais fixe + pourcentage du montant).
     */
    private function computeOperatorFee(Operator $operator, float $amount): float
    {
        return round((float) $operator->fixed_fee + ($amount * (float) $operator->percent_fee), 2);
    }

    /**
     * GET /api/transactions
     * Liste des transactions récentes du marchand authentifié
     */
    public function recentTransactions(Request $request)
    {
        try {
            $user = Auth::user();

            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $transactions = Transaction::where('user_id', $user->id)
                ->latest()
                ->take(5)
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $transactions,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des transactions.',
            ], 500);
        }
    }

    /**
     * GET /api/history?page=1&type=all
     * Historique complet filtré par marchand avec pagination
     *
     * @return JsonResponse
     */
    public function historyList(Request $request)
    {
        try {
            $user = Auth::user();

            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthenticated.',
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
                'type' => $type,
                'data' => $transactions,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Impossible de charger l\'historique.',
            ], 500);
        }
    }

    /**
     * GET /api/get_request?request_id=...
     * Alias historique de getTransactionStatus() basé sur un paramètre de requête
     * (au lieu d'un paramètre de route), utilisé par certains clients existants.
     */
    public function checkStatus(Request $request)
    {
        $id = $request->query('request_id', $request->query('id'));

        if (! $id) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Le paramètre request_id est requis.',
            ], 422);
        }

        return $this->getTransactionStatus($id);
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

            if (! $transaction) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Transaction introuvable.',
                ], 404);
            }

            // 2. Retour de la réponse structurée pour le WaitingScreen de Flutter
            return response()->json([
                'status' => $transaction->status, // 'success', 'pending', ou 'failed'
                'message' => $transaction->failure_reason ?? 'Statut de la transaction récupéré.',
                'data' => [
                    'id' => $transaction->id,
                    'request_id' => $transaction->reference,
                    'amount' => $transaction->amount_sent,
                    'number' => $transaction->recipient_phone,
                    'carrier' => $transaction->recipient_operator,
                    'updated_at' => $transaction->updated_at->toIso8601String(),
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Erreur lors de la vérification du statut : '.$e->getMessage(),
            ], 500);
        }
    }
}
