<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Wallet et transactions du marchand connecté, pour son portail (session Sanctum,
 * role='merchant'). Couvre les deux environnements : le solde réel et les
 * transactions live, le solde fictif et les transactions sandbox simulées.
 */
class PortalController extends Controller
{
    public function wallet(Request $request): JsonResponse
    {
        $wallet = $request->user()->wallet;

        return response()->json([
            'status' => 'success',
            'data' => [
                'currency' => $wallet->currency,
                'balance' => (float) $wallet->balance,
                'sandbox_balance' => (float) $wallet->sandbox_balance,
                'sandbox_max_balance' => Wallet::SANDBOX_MAX_BALANCE,
            ],
        ], 200);
    }

    public function transactions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'environment' => ['required', Rule::in(['sandbox', 'production'])],
            'type' => ['sometimes', Rule::in(['transfer', 'withdrawal', 'deposit'])],
            'status' => ['sometimes', Rule::in(['pending', 'processing', 'success', 'failed', 'reversed'])],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $transactions = Transaction::where('user_id', $request->user()->id)
            ->where('environment', $validated['environment'])
            ->when($validated['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['search'] ?? null, fn ($q, $search) => $q->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")->orWhere('recipient_phone', 'like', "%{$search}%");
            }))
            ->latest()
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'status' => 'success',
            'data' => $transactions->getCollection()->map(fn (Transaction $t) => $t->toMerchantArray()),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ], 200);
    }

    /**
     * Recharge le solde sandbox (argent fictif) pour continuer les tests, dans la
     * limite de Wallet::SANDBOX_MAX_BALANCE. N'a aucun effet sur le solde réel.
     */
    public function topUpSandbox(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:'.Wallet::SANDBOX_MAX_BALANCE],
        ]);

        $wallet = DB::transaction(function () use ($request, $validated) {
            $wallet = Wallet::where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();

            if ($wallet->sandbox_balance + $validated['amount'] > Wallet::SANDBOX_MAX_BALANCE) {
                return null;
            }

            $wallet->increment('sandbox_balance', $validated['amount']);

            return $wallet;
        });

        if (! $wallet) {
            return response()->json([
                'status' => 'error',
                'message' => 'Le solde sandbox ne peut pas dépasser '.number_format(Wallet::SANDBOX_MAX_BALANCE, 0, ',', ' ').'.',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Solde sandbox rechargé.',
            'data' => ['sandbox_balance' => (float) $wallet->sandbox_balance],
        ], 200);
    }
}
