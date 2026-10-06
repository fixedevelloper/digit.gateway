<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Exports\MerchantTransactionsExport;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Gestion des comptes marchands B2B (intégrateurs de la passerelle) : un marchand
 * est un User avec role='merchant', qui réutilise le wallet et l'infrastructure
 * de transactions déjà en place pour les clients mobile money.
 */
class MerchantController extends Controller
{
    /**
     * Liste les comptes marchands avec leur solde. Consommé par le composant
     * Next.js 'MerchantsPage' (Gestion des Marchands B2B).
     */
    public function index()
    {
        $merchants = User::where('role', 'merchant')
            ->with('wallet:id,user_id,balance,currency')
            ->orderBy('company_name')
            ->withCount('merchantDocuments')
            ->get(['id', 'name', 'email', 'phone', 'company_name', 'environment', 'status', 'kyb_status', 'kyb_grace_until', 'created_at']);

        return response()->json($merchants, 200);
    }

    /**
     * Met à jour un compte marchand : bascule sandbox/production, suspension
     * (révocation d'accès) ou correction des informations de contact.
     */
    public function update(Request $request, string $id)
    {
        $merchant = User::where('role', 'merchant')->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'company_name' => 'sometimes|nullable|string|max:255',
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($merchant->id)],
            'environment' => 'sometimes|in:sandbox,production',
            'status' => 'sometimes|boolean',
        ]);

        // Passage en production : uniquement pour un dossier de vérification (KYB) approuvé.
        // Un marchand déjà en production n'est pas concerné (ni rétrogradé, ni bloqué pour autre chose).
        if (($validated['environment'] ?? null) === 'production' && $merchant->environment !== 'production' && $merchant->kyb_status !== 'approved') {
            return response()->json([
                'status' => 'error',
                'error_code' => 'KYB_REQUIRED',
                'message' => 'Le dossier de vérification de ce marchand n\'est pas approuvé : le passage en production est refusé.',
            ], 422);
        }

        $merchant->update($validated);

        // Suspension : coupe immédiatement les sessions ouvertes (tokens Sanctum).
        if (array_key_exists('status', $validated) && ! $validated['status']) {
            $merchant->tokens()->delete();
        }
        $merchant->load('wallet:id,user_id,balance,currency');

        return response()->json([
            'status' => 'success',
            'message' => 'Compte marchand mis à jour avec succès.',
            'data' => $merchant,
        ], 200);
    }

    /**
     * Transactions d'un marchand, dans l'un ou l'autre environnement (la liste générale de l'admin exclut la sandbox).
     * Le résumé (volume réussi, frais, échecs...) porte sur les mêmes filtres que la liste, sans la pagination.
     */
    public function transactions(Request $request, string $id)
    {
        $merchant = User::where('role', 'merchant')->findOrFail($id);
        $filters = $this->transactionFilters($request, withPagination: true);
        $query = $this->merchantTransactionsQuery($merchant, $filters);

        $summary = $this->summarize($query);
        $page = $query->latest('id')->paginate($filters['per_page'] ?? 20);

        return response()->json([
            'status' => 'success',
            'merchant' => $merchant->only(['id', 'company_name', 'name', 'environment']),
            'summary' => $summary,
            'data' => $page->getCollection()->map(fn (Transaction $t) => $t->toMerchantArray([
                'id' => $t->id,
                'channel' => $t->channel,
                'gateway_reference' => $t->gateway_reference,
            ])),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Export Excel des mêmes transactions (mêmes filtres que la liste, sans pagination) : feuille « Résumé » + feuille
     * « Transactions ».
     */
    public function exportTransactions(Request $request, string $id)
    {
        $merchant = User::where('role', 'merchant')->findOrFail($id);
        $filters = $this->transactionFilters($request, withPagination: false);
        $query = $this->merchantTransactionsQuery($merchant, $filters);

        $summary = $this->summarize($query);

        // Au-delà, l'admin doit affiner les filtres (période, statut...).
        $max = (int) config('exports.merchant_transactions_max_rows', 100000);

        if ($summary['total'] > $max) {
            return response()->json([
                'status' => 'error',
                'message' => 'Trop de transactions à exporter ('.number_format($summary['total'], 0, ',', ' ').', maximum '.number_format($max, 0, ',', ' ').') : restreignez la période ou le statut.',
            ], 422);
        }

        $name = sprintf('transactions_%s_%s_%s.xlsx', Str::slug($merchant->company_name ?: $merchant->name) ?: "marchand-{$merchant->id}", $filters['environment'], now()->format('Y-m-d_His'));

        return Excel::download(new MerchantTransactionsExport($merchant, $query->with('bankBeneficiary'), $filters['environment'], $filters, $summary), $name);
    }

    /** @return array<string, mixed> */
    private function transactionFilters(Request $request, bool $withPagination): array
    {
        return $request->validate([
            'environment' => ['required', Rule::in(['sandbox', 'production'])],
            'type' => ['sometimes', Rule::in(['transfer', 'withdrawal', 'deposit'])],
            'status' => ['sometimes', Rule::in(['pending', 'pending_manual_review', 'assigned', 'processing', 'success', 'failed', 'rejected', 'cancelled', 'reversed'])],
            'search' => ['sometimes', 'string', 'max:100'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
        ] + ($withPagination ? ['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']] : []));
    }

    private function merchantTransactionsQuery(User $merchant, array $filters)
    {
        return Transaction::where('user_id', $merchant->id)
            ->where('environment', $filters['environment'])
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q
                ->where('reference', 'like', "%{$v}%")->orWhere('recipient_phone', 'like', "%{$v}%")));
    }

    /** @return array{total: int, success_count: int, failed_count: int, success_volume: float, success_fees: float} */
    private function summarize($query): array
    {
        $row = (clone $query)->selectRaw(
            'COUNT(*) as total,
             SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as success_count,
             SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) as failed_count,
             COALESCE(SUM(CASE WHEN status = ? THEN amount_sent ELSE 0 END), 0) as success_volume,
             COALESCE(SUM(CASE WHEN status = ? THEN fees ELSE 0 END), 0) as success_fees',
            ['success', 'failed', 'rejected', 'reversed', 'success', 'success']
        )->first();

        return [
            'total' => (int) $row->total,
            'success_count' => (int) $row->success_count,
            'failed_count' => (int) $row->failed_count,
            'success_volume' => (float) $row->success_volume,
            'success_fees' => (float) $row->success_fees,
        ];
    }
}
