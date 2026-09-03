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
            },
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
}
