<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;

class CountryController extends Controller
{
    /**
     * Récupérer la liste de tous les pays actifs pour l'application mobile.
     * Idéal pour alimenter un sélecteur de pays/devises (ex: CM, CI, SN).
     */
    public function index()
    {
        // 1. On récupère les pays avec leur relation 'operators'
        $countries = Country::where('status', true)
            ->with(['operators' => function ($query) {
                $query->where('status', true);
            }])
            ->orderBy('name', 'asc')
            ->get();

        // 2. On transforme la collection pour renommer la clé au niveau du JSON
        $formattedCountries = $countries->map(function ($country) {
            // Convertit le modèle en tableau
            $countryArray = $country->toArray();
            if ($country['flag'] && ! str_starts_with($country['flag'], 'http')) {
                $countryArray['flag_url'] = asset('storage/'.$country['flag']);
            } else {
                $countryArray['flag_url'] = $country['flag'];
            }
            // On bascule les données d'operators vers la clé carriers
            $countryArray['carriers'] = $countryArray['operators'];

            // On supprime l'ancienne clé pour garder le JSON propre
            unset($countryArray['operators']);

            return $countryArray;
        });

        return response()->json([
            'status' => 'success',
            'count' => $formattedCountries->count(),
            'data' => $formattedCountries,
        ], 200);
    }

    /**
     * Récupérer les détails d'un pays spécifique via son code ISO (ex: CM).
     */
    public function show($iso)
    {
        $country = Country::where('iso', strtoupper($iso))->first();

        if (! $country) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pays non trouvé ou non pris en charge.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $country,
        ], 200);
    }
}
