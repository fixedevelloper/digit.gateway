<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestion des taux de change manuels depuis le dashboard. `rate` = unités de
 * `quote_currency` pour 1 `base_currency` (ex: USD → XAF = 605). Un seul sens
 * suffit : le sens inverse est calculé automatiquement (ExchangeRateService).
 */
class ExchangeRateController extends Controller
{
    /**
     * Taux en vigueur (le plus récent de chaque paire) et historique récent.
     */
    public function index(): JsonResponse
    {
        // Table saisie à la main : quelques lignes par paire, on peut tout charger.
        $rates = ExchangeRate::with('author:id,name')->latest('id')->get();

        return response()->json([
            'current' => $rates->unique(fn (ExchangeRate $rate) => $rate->base_currency.'/'.$rate->quote_currency)->values(),
            'history' => $rates->take(100)->values(),
        ], 200);
    }

    /**
     * Définit un nouveau taux pour une paire (s'applique aux cotations suivantes ;
     * les cotations déjà émises gardent leur taux jusqu'à expiration).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'base_currency' => 'required|string|size:3|alpha',
            'quote_currency' => 'required|string|size:3|alpha|different:base_currency',
            'rate' => 'required|numeric|gt:0',
        ]);

        $rate = ExchangeRate::create([
            'base_currency' => strtoupper($validated['base_currency']),
            'quote_currency' => strtoupper($validated['quote_currency']),
            'rate' => $validated['rate'],
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Taux 1 {$rate->base_currency} = {$rate->rate} {$rate->quote_currency} enregistré.",
            'data' => $rate,
        ], 201);
    }
}
