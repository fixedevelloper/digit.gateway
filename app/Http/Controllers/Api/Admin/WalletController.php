<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WalletAdjustment;
use Illuminate\Http\Request;
use App\Services\WalletAdjustmentService;
use InvalidArgumentException;

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
     * Demande (ou applique) un ajustement manuel de solde. Voir WalletAdjustmentService :
     * 200 si appliqué immédiatement (superadmin, sous le seuil), 202 si en attente de la
     * validation d'un autre superadmin.
     * Consommé par le composant Next.js 'AdjustWalletModal'.
     */
    public function adjust(Request $request, WalletAdjustmentService $adjustments, $id)
    {
        $data = $request->validate([
            'type' => 'required|in:credit,debit',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|min:5|max:255',
        ]);

        $wallet = Wallet::findOrFail($id);

        try {
            $adjustment = $adjustments->request($request->user(), $wallet, $data['type'], (float) $data['amount'], $data['reason']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        if ($adjustment->status === WalletAdjustment::PENDING) {
            return response()->json([
                'status' => 'pending_approval',
                'message' => 'Demande enregistrée : elle doit être approuvée par un autre superadmin avant d\'être appliquée.',
                'data' => $adjustment,
            ], 202);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Ajustement de solde appliqué avec succès.',
            'balance' => $adjustment->balance_after,
            'data' => $adjustment,
        ], 200);
    }

    /** Demandes d'ajustement (par défaut en attente), pour l'écran de validation. */
    public function pendingAdjustments(Request $request)
    {
        return response()->json(
            WalletAdjustment::with(['admin:id,name,phone,role', 'wallet.user:id,name,phone,role', 'reviewer:id,name'])
                ->where('status', $request->query('status', WalletAdjustment::PENDING))
                ->latest('id')
                ->paginate(30)
        );
    }

    public function approveAdjustment(Request $request, WalletAdjustmentService $adjustments, string $id)
    {
        try {
            $adjustment = $adjustments->approve(WalletAdjustment::findOrFail($id), $request->user());
        } catch (InvalidArgumentException $e) {
            return $e->getMessage() === 'SELF_APPROVAL'
                ? response()->json(['status' => 'error', 'error_code' => 'SELF_APPROVAL', 'message' => 'Vous ne pouvez pas approuver votre propre demande.'], 403)
                : response()->json(['status' => 'error', 'message' => $e->getMessage()], 409);
        }

        return response()->json(['status' => 'success', 'data' => $adjustment]);
    }

    public function rejectAdjustment(Request $request, WalletAdjustmentService $adjustments, string $id)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:255']);

        try {
            $adjustment = $adjustments->reject(WalletAdjustment::findOrFail($id), $request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 409);
        }

        return response()->json(['status' => 'success', 'data' => $adjustment]);
    }

    /**
     * Historique des ajustements manuels (crédit/débit) effectués sur un portefeuille.
     * Consommé par le dashboard pour justifier une correction de solde a posteriori.
     */
    public function adjustments(string $id)
    {
        $wallet = Wallet::findOrFail($id);

        $adjustments = $wallet->adjustments()
            ->where('status', WalletAdjustment::APPROVED)
            ->with('admin:id,name,phone')
            ->latest()
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => $adjustments,
        ], 200);
    }
}
