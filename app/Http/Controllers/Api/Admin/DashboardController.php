<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction; // Ajuste selon le nom de ton modèle de transactions
use App\Models\User;
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

        // 4. Nombre de comptes utilisateurs actifs (status = true)
        $activeAccountsCount = User::where('status', true)->count();

        // 5. Génération de l'historique des 7 derniers jours (Crédit vs Débit)
        $sevenDaysAgo = Carbon::now()->subDays(6)->startOfDay();

        // Requête groupée par jour et par type de transaction.
        // 'deposit' = argent entrant dans le wallet (crédit) ; 'withdrawal'/'transfer'/'payment' = argent sortant (débit).
        $rawFlows = Transaction::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw("SUM(CASE WHEN type = 'deposit' THEN amount_sent ELSE 0 END) as total_credit"),
            DB::raw("SUM(CASE WHEN type IN ('withdrawal', 'transfer', 'payment') THEN amount_sent ELSE 0 END) as total_debit")
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

        // Réponse à plat, alignée sur la convention des autres contrôleurs admin
        // (WalletController, MerchantController, etc. renvoient déjà le payload sans wrapper).
        return response()->json([
            'monthlyVolume' => (float) $monthlyVolume,
            'successfulTransactionsCount' => $successfulTransactionsCount,
            'successRate' => $successRate,
            'activeAccountsCount' => $activeAccountsCount,
            'dailyHistory' => $dailyHistory,
        ]);
    }
}
