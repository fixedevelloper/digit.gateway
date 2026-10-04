<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DepositRequest;
use App\Http\Requests\TransferRequest;
use App\Http\Requests\WithdrawalRequest;
use App\Models\Agency;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse; // <- Ajouté pour l'authentification
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TransferController extends Controller
{
    public function __construct(private readonly TransactionService $transactions)
    {
    }

    /**
     * Initier un transfert d'argent
     *
     * Débite immédiatement le solde du compte puis met l'envoi vers l'opérateur mobile
     * money en file d'attente pour traitement asynchrone. Le statut final se suit via
     * GET /transactions/{id}/status.
     */
    public function initiateTransfer(TransferRequest $request)
    {
        // Authentification (auth:sanctum) + PIN ('pin.verify') + anti-doublon ('idempotent:transfer')
        // sont déjà garantis par les middlewares de la route à ce stade.
        $user = Auth::user();
        $amount = (float) $request->amount;

        try {
            $result = $this->transactions->createTransfer(
                $user,
                $request->only(['country', 'carrier', 'currency', 'operator_id', 'quote_id', 'number', 'amount']),
                idempotencyKey: $request->header('Idempotency-Key'),
            );
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

        return response()->json([
            'status' => 'success',
            'message' => 'Request accepted, processing in progress',
            'amount' => $amount,
            'fee_charged' => $result['fee'],
            'total' => $result['total'],
            'currency' => $result['transaction']->currency_sent,
            'amount_received' => (float) $result['transaction']->amount_to_receive,
            'currency_received' => $result['transaction']->currency_received,
            'exchange_rate' => (float) $result['transaction']->exchange_rate,
            'remaining_balance' => (float) $result['balance'],
            'request_id' => $result['transaction']->reference,
            'processing_mode' => $result['transaction']->processing_mode->value,
            'transfer_status' => $result['transaction']->status,
        ], 200);
    }

    /**
     * Initier un retrait (cash-out)
     *
     * Vérifie l'agence de retrait via son code (`agensic_code`), débite le solde du compte
     * puis met la demande en file d'attente pour traitement par l'opérateur mobile money.
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

        try {
            $result = $this->transactions->createWithdrawal($user, $agency, $request->only(['country', 'carrier', 'currency', 'operator_id', 'quote_id', 'number', 'amount']));
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

        return response()->json([
            'status' => 'success',
            'message' => 'Request accepted, processing in progress',
            'amount' => $amount,
            'fee_charged' => $result['fee'],
            'total' => $result['total'],
            'currency' => $result['transaction']->currency_sent,
            'amount_received' => (float) $result['transaction']->amount_to_receive,
            'currency_received' => $result['transaction']->currency_received,
            'exchange_rate' => (float) $result['transaction']->exchange_rate,
            'remaining_balance' => (float) $result['balance'],
            'request_id' => $result['transaction']->reference,
        ], 200);
    }

    /**
     * Initier un dépôt (cash-in)
     *
     * Enregistre une demande de dépôt (crédit du solde) et la met en file d'attente pour
     * traitement par l'opérateur mobile money.
     */
    public function initiateDeposit(DepositRequest $request)
    {
        // Authentification (auth:sanctum) + PIN ('pin.verify') + anti-doublon ('idempotent:deposit')
        // sont déjà garantis par les middlewares de la route à ce stade.
        $user = Auth::user();
        $amount = (float) $request->amount;

        try {
            $result = $this->transactions->createDeposit($user, $request->only(['country', 'carrier', 'currency', 'operator_id', 'quote_id', 'number', 'amount']));
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first(),
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred during the withdrawal request.',
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Request accepted, processing in progress',
            'amount' => $amount,
            'fee_charged' => $result['fee'],
            'total' => $result['total'],
            'currency' => $result['transaction']->currency_sent,
            'amount_received' => (float) $result['transaction']->amount_to_receive,
            'currency_received' => $result['transaction']->currency_received,
            'exchange_rate' => (float) $result['transaction']->exchange_rate,
            'remaining_balance' => (float) $result['balance'],
            'request_id' => $result['transaction']->reference,
        ], 200);
    }

    /**
     * Lister les transactions récentes
     *
     * Retourne les 5 dernières transactions du compte authentifié, toutes natures
     * confondues (transfert, retrait, dépôt).
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
     * Historique paginé des transactions
     *
     * Retourne l'historique complet des transactions du compte authentifié, paginé
     * (15 par page) et filtrable via le paramètre `type` (`transfer`, `deposit`,
     * `withdrawal`, `payment`, ou `all` par défaut).
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
     * Statistiques agrégées de l'utilisateur sur une période.
     *
     * URL: GET /api/stats?period=week|month|year
     */
    public function stats(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
            }

            $now = now();
            $since = match ($request->query('period', 'month')) {
                'week' => $now->copy()->subDays(6)->startOfDay(),
                'year' => $now->copy()->startOfYear(),
                default => $now->copy()->startOfMonth(),
            };

            $okStatuses = ['success', 'completed'];
            $koStatuses = ['failed', 'rejected', 'reversed'];

            $base = fn () => Transaction::where('user_id', $user->id)->where('created_at', '>=', $since);

            $inflow = (float) $base()->whereIn('status', $okStatuses)->where('type', 'deposit')->sum('amount_sent');
            $outflow = (float) $base()->whereIn('status', $okStatuses)->whereIn('type', ['transfer', 'withdrawal'])->sum('amount_sent');

            $ok = $base()->whereIn('status', $okStatuses)->count();
            $ko = $base()->whereIn('status', $koStatuses)->count();

            $operators = $base()->whereIn('status', $okStatuses)
                ->selectRaw('recipient_operator as operator, SUM(amount_sent) as volume')
                ->groupBy('recipient_operator')
                ->orderByDesc('volume')
                ->get()
                ->map(fn ($r) => ['operator' => $r->operator ?: 'Opérateur', 'volume' => (float) $r->volume])
                ->values();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'inflow' => $inflow,
                    'outflow' => $outflow,
                    'success_rate' => ($ok + $ko) > 0 ? (int) round($ok * 100 / ($ok + $ko)) : 0,
                    'operators' => $operators,
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('stats error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Impossible de charger les statistiques.'], 500);
        }
    }

    /**
     * Vérifier le statut d'une transaction (par paramètre de requête)
     *
     * Alias de GET /transactions/{id}/status attendant l'identifiant via `?request_id=...`
     * (ou `?id=...`) plutôt qu'un paramètre de route ; conservé pour compatibilité avec
     * certains clients existants.
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
     * Récupérer le statut d'une transaction
     *
     * Retourne l'état courant (`pending`, `processing`, `success`, `failed`, `reversed`)
     * d'une transaction identifiée par sa référence ou son id technique. Conçu pour le
     * polling côté client (écran d'attente mobile, vérification côté serveur marchand).
     */
    public function getTransactionStatus(string $id)
    {
        try {
            // 1. Recherche de la transaction en base de données, parmi celles de
            // l'utilisateur connecté uniquement (référence ou id technique)
            $transaction = Transaction::where('user_id', Auth::id())
                ->where(function ($query) use ($id) {
                    $query->where('reference', $id)->orWhere('id', $id);
                })
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
            Log::error('Erreur vérification statut : '.$e->getMessage());

            return response()->json([
                'status' => 'failed',
                'message' => 'Erreur lors de la vérification du statut.',
            ], 500);
        }
    }
}
