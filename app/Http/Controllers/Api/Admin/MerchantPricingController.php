<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MerchantPricing;
use App\Models\Operator;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use App\Models\Country;

/**
 * Tarifs d'un marchand par opérateur (pays + opérateur) : frais fixe, pourcentage et taux de change
 * saisi à la main. Ces valeurs remplacent, pour ce marchand seulement, les frais de l'opérateur et le
 * taux global (réservé aux clients de l'app). Sans tarif actif, le marchand ne peut pas opérer.
 */
class MerchantPricingController extends Controller
{
    /** Tous les opérateurs actifs avec le tarif du marchand (null = non configuré). */
    public function index(string $id): JsonResponse
    {
        Gate::authorize('manage', Country::class);
        $merchant = $this->merchant($id);

        $pricing = MerchantPricing::where('merchant_id', $merchant->id)->get()->keyBy('operator_id');

        $rows = Operator::with('country:id,name,iso')->orderBy('country_id')->orderBy('name')->get()
            ->map(fn (Operator $op) => [
                'operator_id' => $op->id,
                'operator' => $op->name,
                'code' => $op->code,
                'currency' => $op->currency,
                'operator_status' => (bool) $op->status,
                'country' => $op->country?->name,
                'iso' => $op->country?->iso,
                'pricing' => $pricing->get($op->id),
            ]);

        return response()->json(['wallet_currency' => $merchant->wallet?->currency, 'data' => $rows]);
    }

    /** Crée ou remplace le tarif du marchand pour un opérateur. */
    public function upsert(Request $request, string $id, string $operatorId): JsonResponse
    {
        Gate::authorize('manage', Country::class);
        $merchant = $this->merchant($id);
        $operator = Operator::findOrFail($operatorId);

        $data = $request->validate([
            'fixed_fee' => 'required|numeric|min:0',
            'percent_fee' => 'required|numeric|between:0,1', // 0.0100 = 1 %
            'rate' => 'nullable|numeric|gt:0',
            'active' => 'sometimes|boolean',
        ]);

        // Taux obligatoire dès que l'opérateur travaille dans une autre devise que le wallet du marchand.
        $walletCurrency = $merchant->wallet?->currency;
        if ($walletCurrency && $operator->currency !== $walletCurrency && empty($data['rate'])) {
            return response()->json([
                'message' => "Le taux est requis : {$operator->name} travaille en {$operator->currency} et le wallet du marchand en {$walletCurrency}.",
                'errors' => ['rate' => ['Taux requis.']],
            ], 422);
        }

        $pricing = MerchantPricing::updateOrCreate(
            ['merchant_id' => $merchant->id, 'operator_id' => $operator->id],
            $data + ['rate' => null, 'updated_by' => $request->user()->id],
        );

        Log::info('[PRICING] Tarif marchand modifié', [
            'merchant_id' => $merchant->id,
            'operator_id' => $operator->id,
            'by' => $request->user()->id,
            'values' => $pricing->only(['fixed_fee', 'percent_fee', 'rate', 'active']),
        ]);

        return response()->json(['status' => 'success', 'data' => $pricing->fresh()]);
    }

    public function destroy(Request $request, string $id, string $operatorId): JsonResponse
    {
        Gate::authorize('manage', Country::class);
        $merchant = $this->merchant($id);

        MerchantPricing::where('merchant_id', $merchant->id)->where('operator_id', $operatorId)->delete();

        Log::info('[PRICING] Tarif marchand supprimé', ['merchant_id' => $merchant->id, 'operator_id' => $operatorId, 'by' => $request->user()->id]);

        return response()->json(['status' => 'success', 'message' => 'Tarif supprimé.']);
    }

    private function merchant(string $id): User
    {
        return User::where('role', 'merchant')->findOrFail($id);
    }
}
