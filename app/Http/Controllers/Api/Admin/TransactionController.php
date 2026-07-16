<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * Récupère le grand livre des transactions pour l'administration.
     * Consommé par le composant Next.js 'TransactionTable'.
     */
    public function index(Request $request)
    {
        // 1. Initialisation de la requête avec les relations requises
        // On optimise en ne sélectionnant que les colonnes nécessaires pour les relations
        $query = Transaction::with([
            'user' => function ($q) {
                $q->select('id', 'name', 'phone', 'role');
            },
            'recipient' => function ($q) {
                $q->select('id', 'name', 'phone', 'operator');
            }
        ]);

        // 2. Filtres optionnels pour l'administration (par statut, type ou opérateur)
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('operator')) {
            $query->where('recipient_operator', $request->operator);
        }

        // 3. Extraction par ordre chronologique inversé (les plus récentes d'abord)
        // Limité aux 100 dernières transactions par défaut pour préserver les performances
        $transactions = $query->orderBy('created_at', 'desc')->take(100)->get();

        return response()->json($transactions, 200);
    }

    /**
     * Permet d'obtenir des statistiques financières agrégées (Volume, Marge net, Échecs)
     * pour alimenter les indicateurs clés (KPI) de la page d'accueil d'administration.
     */
    public function stats()
    {
        // Calcul du volume global sur les transactions réussies (success)
        $totalVolume = Transaction::where('status', 'success')->sum('amount_sent');

        // Calcul des frais totaux collectés auprès des clients
        $totalFeesCollected = Transaction::where('status', 'success')->sum('fees');

        // Calcul des frais totaux facturés par les agrégateurs/opérateurs en tâche de fond
        $totalGatewayFees = Transaction::where('status', 'success')->sum('gateway_fees');

        // Marge nette générée par Digit-Gateway (Frais clients - Frais opérateurs)
        $netMargin = $totalFeesCollected - $totalGatewayFees;

        // Nombre de transactions en échec ou timeouts opérateur
        $failedCount = Transaction::where('status', 'failed')->count();

        // Taux de réussite global du système
        $totalCount = Transaction::count();
        $successRate = $totalCount > 0
            ? round((Transaction::where('status', 'success')->count() / $totalCount) * 100, 2)
            : 100;

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_volume'     => $totalVolume,
                'net_margin'       => $netMargin,
                'failed_count'     => $failedCount,
                'success_rate'     => $successRate . '%',
                'currency'         => 'XAF'
            ]
        ], 200);
    }
}
