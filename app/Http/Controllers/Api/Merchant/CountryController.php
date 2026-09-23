<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

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
    public function index(): JsonResponse
    {
        $countries = Country::where('status', true)
            ->with(['operators' => function ($query) {
                $query->where('status', true);
            }])
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $countries->map(fn (Country $country) => $this->formatCountry($country)),
        ], 200);
    }

    /**
     * Détails d'un pays
     *
     * Récupère un pays spécifique via son code ISO (ex: CM, CI, SN).
     */
    public function show(string $iso): JsonResponse
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
            'data' => $this->formatCountry($country),
        ], 200);
    }

    private function formatCountry(Country $country): array
    {
        return [
            'name' => $country->name,
            'iso' => $country->iso,
            'currency' => $country->currency,
            'phonecode' => $country->phonecode,
            'carriers' => $country->operators->map(fn ($operator) => [
                'id' => $operator->id,
                'code' => $operator->code,
                'name' => $operator->name,
                // Devise des bornes/frais ci-dessous et du montant versé par l'opérateur.
                // Différente de celle du wallet ⇒ cotation obligatoire (POST /quotes).
                'currency' => $operator->currency,
                'min_amount' => (float) $operator->min_amount,
                'max_amount' => (float) $operator->max_amount,
                'fixed_fee' => (float) $operator->fixed_fee,
                'percent_fee' => (float) $operator->percent_fee,
            ]),
        ];
    }
}
