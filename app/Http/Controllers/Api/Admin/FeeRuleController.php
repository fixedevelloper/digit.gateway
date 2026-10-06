<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\TransferService;
use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\FeeRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Règles de frais par pays, service, provider, devise et tranche de montant
 * (cf. FeeCalculator). Une règle peut être désactivée (`active=false`, réversible) ou supprimée :
 * les transactions existantes conservent les frais qu'elles ont déjà enregistrés.
 */
class FeeRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage', Country::class);

        return response()->json(
            FeeRule::query()
                ->when($request->query('service'), fn ($q, $s) => $q->where('service', $s))
                ->when($request->query('country_id'), fn ($q, $id) => $q->where('country_id', $id))
                ->orderBy('service')->orderBy('country_id')->orderBy('min_amount')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage', Country::class);

        return response()->json(['status' => 'success', 'data' => FeeRule::create($this->validated($request))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        Gate::authorize('manage', Country::class);

        $rule = FeeRule::findOrFail($id);
        $rule->update($this->validated($request, sometimes: true));

        return response()->json(['status' => 'success', 'data' => $rule->fresh()]);
    }

    public function destroy(string $id): JsonResponse
    {
        Gate::authorize('manage', Country::class);

        FeeRule::findOrFail($id)->delete();

        return response()->json(['status' => 'success', 'message' => 'Règle de frais supprimée.']);
    }

    private function validated(Request $request, bool $sometimes = false): array
    {
        $r = $sometimes ? 'sometimes|' : 'required|';

        $data = $request->validate([
            'country_id' => 'sometimes|nullable|integer|exists:countries,id',
            'service' => [$sometimes ? 'sometimes' : 'required', Rule::enum(TransferService::class)],
            'provider_id' => 'sometimes|nullable|integer|exists:providers,id',
            'currency' => $r.'string|size:3',
            'min_amount' => 'sometimes|numeric|min:0',
            'max_amount' => 'sometimes|nullable|numeric|gt:min_amount',
            'fixed_fee' => $sometimes ? 'sometimes|numeric|min:0' : 'required_without:percent_fee|numeric|min:0',
            'percent_fee' => 'sometimes|numeric|between:0,1', // 0.0100 = 1 %
            'active' => 'sometimes|boolean',
        ]);

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        return $data;
    }
}
