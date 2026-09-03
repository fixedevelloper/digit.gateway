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
            ->get(['id', 'name', 'email', 'phone', 'company_name', 'environment', 'status', 'created_at']);

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

        $merchant->update($validated);
        $merchant->load('wallet:id,user_id,balance,currency');

        return response()->json([
            'status' => 'success',
            'message' => 'Compte marchand mis à jour avec succès.',
            'data' => $merchant,
        ], 200);
    }
}
