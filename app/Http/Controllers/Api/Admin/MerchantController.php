<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
}
