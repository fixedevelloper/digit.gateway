<?php

namespace App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Controller;
use App\Models\Transaction; // Ajuste selon le nom de ton modèle de transactions
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Récupère les métriques de synthèse et les séries temporelles du Dashboard.
     */
    public function getStats(): JsonResponse
    {
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();

        // 1. Calcul du volume total transféré ce mois-ci (uniquement les transactions réussies)
        $monthlyVolume = Transaction::where('status', 'success')
            ->where('created_at', '>=', $startOfMonth)
            ->sum('amount_sent');

        // 2. Nombre total de transactions réussies ce mois-ci
        $successfulTransactionsCount = Transaction::where('status', 'success')
            ->where('created_at', '>=', $startOfMonth)
            ->count();

        // 3. Taux de succès global (Réussies / Total initiées)
        $totalTransactions = Transaction::where('created_at', '>=', $startOfMonth)->count();
        $successRate = $totalTransactions > 0
            ? round(($successfulTransactionsCount / $totalTransactions) * 100, 1)
            : 100.0;

        // 4. Génération de l'historique des 7 derniers jours (Crédit vs Débit)
        $sevenDaysAgo = Carbon::now()->subDays(6)->startOfDay();

        // Requête groupée par jour et par type de transaction (ex: 'credit' = dépôt, 'debit' = retrait/paiement)
        $rawFlows = Transaction::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw("SUM(CASE WHEN type = 'credit' THEN amount_sent ELSE 0 END) as total_credit"),
            DB::raw("SUM(CASE WHEN type = 'debit' THEN amount_to_receive ELSE 0 END) as total_debit")
        )
            ->where('status', 'success')
            ->where('created_at', '>=', $sevenDaysAgo)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date', 'ASC')
            ->get()
            ->keyBy('date');

        // Remplissage des jours vides pour éviter les "trous" dans le graphique
        $dailyHistory = [];
        for ($i = 6; $i >= 0; $i--) {
            $dateString = Carbon::now()->subDays($i)->format('Y-m-d');
            $flow = $rawFlows->get($dateString);

            $dailyHistory[] = [
                'date' => Carbon::parse($dateString)->translatedFormat('d M'), // Ex: "16 Juil"
                'credit' => $flow ? (float) $flow->total_credit : 0.0,
                'debit' => $flow ? (float) $flow->total_debit : 0.0,
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'monthlyVolume' => (float) $monthlyVolume,
                'successfulTransactionsCount' => $successfulTransactionsCount,
                'successRate' => $successRate,
                'dailyHistory' => $dailyHistory
            ]
        ]);
    }
}
