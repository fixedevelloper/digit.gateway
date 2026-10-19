<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Dedoc\Scramble\Attributes\Group;
use App\Models\MerchantPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Pays et opérateurs disponibles pour l'API gateway B2B. Contrat allégé par
 * rapport à Api\CountryController (app mobile) : pas de `flag_url` (préoccupation
 * d'affichage mobile), mais les bornes min/max et frais par opérateur exposés
 * explicitement pour que l'intégrateur puisse valider côté client avant appel.
 */
#[Group('Merchant Gateway', weight: 1)]
class CountryController extends Controller
{
    /**
     * Lister les pays et opérateurs disponibles
     *
     * Retourne les pays actifs avec leurs opérateurs mobile money actifs et leurs
     * conditions (bornes de montant, frais).
     */
    public function index(Request $request): JsonResponse
    {
        $countries = Country::where('status', true)
            ->with(['operators' => function ($query) {
                $query->where('status', true);
            }])
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $countries->map(fn (Country $country) => $this->formatCountry($country, $this->pricingFor($request))),
        ], 200);
    }

    /**
     * Détails d'un pays
     *
     * Récupère un pays spécifique via son code ISO (ex: CM, CI, SN).
     */
    public function show(Request $request, string $iso): JsonResponse
    {
        $country = Country::where('iso', strtoupper($iso))
            ->with(['operators' => function ($query) {
                $query->where('status', true);
            }])
            ->first();

        if (! $country) {
            return response()->json([
                'status' => 'error',
                'error_code' => 'COUNTRY_NOT_FOUND',
                'message' => 'Pays non trouvé ou non pris en charge.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->formatCountry($country, $this->pricingFor($request)),
        ], 200);
    }

    /** Tarifs du marchand authentifié, indexés par opérateur. */
    private function pricingFor(Request $request): Collection
    {
        return MerchantPricing::where('merchant_id', $request->user()->id)->where('active', true)->get()->keyBy('operator_id');
    }

    private function formatCountry(Country $country, Collection $pricing): array
    {
        return [
            'name' => $country->name,
            'iso' => $country->iso,
            'currency' => $country->currency,
            'phonecode' => $country->phonecode,
            'carriers' => $country->operators->map(fn ($operator) => [
                // false ⇒ aucun tarif configuré pour ce compte : l'opérateur est refusé (PRICING_NOT_CONFIGURED).
                'available' => $pricing->has($operator->id),
                'id' => $operator->id,
                'code' => $operator->code,
                'name' => $operator->name,
                // Devise des bornes/frais ci-dessous et du montant versé par l'opérateur.
                // Différente de celle du wallet ⇒ cotation obligatoire (POST /quotes).
                'currency' => $operator->currency,
                'min_amount' => (float) $operator->min_amount,
                'max_amount' => (float) $operator->max_amount,
                'fixed_fee' => $pricing->has($operator->id) ? $pricing[$operator->id]->fixed_fee : null,
                'percent_fee' => $pricing->has($operator->id) ? $pricing[$operator->id]->percent_fee : null,
                'rate' => $pricing->has($operator->id) ? $pricing[$operator->id]->rate : null,
            ]),
        ];
    }
}
