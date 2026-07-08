<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
        
        // On bascule les données d'operators vers la clé carriers
        $countryArray['carriers'] = $countryArray['operators'];
        
        // On supprime l'ancienne clé pour garder le JSON propre
        unset($countryArray['operators']);
        
        return $countryArray;
    });

    return response()->json([
        'status' => 'success',
        'count' => $formattedCountries->count(),
        'data' => $formattedCountries
    ], 200);
}

    /**
     * Récupérer les détails d'un pays spécifique via son code ISO (ex: CM).
     */
    public function show($iso)
    {
        $country = Country::where('iso', strtoupper($iso))->first();

        if (!$country) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pays non trouvé ou non pris en charge.'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $country
        ], 200);
    }

    /**
     * Ajouter un nouveau pays (Utile pour le Back-office ou configuration initiale).
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|unique:countries,name',
            'iso' => 'required|string|size:2|unique:countries,iso',
            'iso3' => 'nullable|string|size:3',
            'currency' => 'nullable|string|size:3',
            'phonecode' => 'required|integer',
            'status' => 'boolean',
            'flag' => 'nullable|string', // URL ou icône du drapeau
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        $country = Country::create([
            'name' => $request->name,
            'iso' => strtoupper($request->iso),
            'iso3' => $request->iso3 ? strtoupper($request->iso3) : null,
            'currency' => $request->currency ? strtoupper($request->currency) : 'XAF',
            'numcode' => $request->numcode,
            'phonecode' => $request->phonecode,
            'status' => $request->status ?? false,
            'flag' => $request->flag,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Pays ajouté avec succès',
            'data' => $country
        ], 201);
    }

    /**
     * Activer ou désactiver un pays (Gestion administrative).
     */
    public function toggleStatus($id)
    {
        $country = Country::find($id);

        if (!$country) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pays introuvable.'
            ], 404);
        }

        // Bascule de l'état du statut
        $country->status = !$country->status;
        $country->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Le statut du pays a été mis à jour avec succès.',
            'data' => [
                'id' => $country->id,
                'name' => $country->name,
                'status' => $country->status
            ]
        ], 200);
    }
}