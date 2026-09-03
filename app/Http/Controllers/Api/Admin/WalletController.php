<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WalletAdjustment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    /**
     * Récupère la liste de tous les portefeuilles avec les informations du détenteur.
     * Consommé par le composant Next.js 'WalletMonitor'.
     */
    public function index()
    {
        // On récupère les portefeuilles en sélectionnant uniquement les colonnes nécessaires du User
        $wallets = Wallet::with(['user' => function ($query) {
            $query->select('id', 'name', 'phone', 'role');
        }])->orderBy('balance', 'desc')->get();

        return response()->json($wallets, 200);
    }

    /**
     * Procède à un ajustement manuel (Crédit ou Débit) de manière atomique.
     * Consommé par le composant Next.js 'AdjustWalletModal'.
     */
    public function adjust(Request $request, $id)
    {
        // 1. Validation stricte des données reçues du formulaire Next.js
        $request->validate([
            'type' => 'required|in:credit,debit',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|min:5|max:255',
        ]);

        // 2. Utilisation d'une transaction de base de données avec verrouillage (Pessimistic Locking)
        // Indispensable en Fintech pour éviter les conditions de concurrence (Race Conditions)
        try {
            $result = DB::transaction(function () use ($request, $id) {
                // lockForUpdate() bloque la ligne le temps de l'opération financière
                $wallet = Wallet::lockForUpdate()->findOrFail($id);
                $balanceBefore = $wallet->balance;

                if ($request->type === 'credit') {
                    // Utilisation de la méthode native pour incrémenter de manière propre le type décimal
                    $wallet->increment('balance', $request->amount);
                } else {
                    // Sécurité : Impossible de débiter si le solde est insuffisant
                    if ($wallet->balance < $request->amount) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Solde insuffisant pour exécuter ce débit de régularisation.',
                        ], 422);
                    }
                    $wallet->decrement('balance', $request->amount);
                }

                // Journal d'audit comptable : trace qui a ajusté quoi, pourquoi, et l'état
                // avant/après — indispensable pour justifier une correction manuelle de solde.
                WalletAdjustment::create([
                    'wallet_id' => $wallet->id,
                    'admin_id' => $request->user()->id,
                    'type' => $request->type,
                    'amount' => $request->amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $wallet->balance,
                    'reason' => $request->reason,
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Ajustement de solde appliqué avec succès.',
                    'balance' => $wallet->balance,
                ], 200);
            });

            return $result;

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur interne est survenue lors de la transaction financière.',
            ], 500);
        }
    }

    /**
     * Historique des ajustements manuels (crédit/débit) effectués sur un portefeuille.
     * Consommé par le dashboard pour justifier une correction de solde a posteriori.
     */
    public function adjustments(string $id)
    {
        $wallet = Wallet::findOrFail($id);

        $adjustments = $wallet->adjustments()
            ->with('admin:id,name,phone')
            ->latest()
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => $adjustments,
        ], 200);
    }
}
