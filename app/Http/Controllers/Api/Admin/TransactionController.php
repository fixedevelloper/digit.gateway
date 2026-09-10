<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exports\TransactionsExport;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class TransactionController extends Controller
{
    /**
     * Récupère le grand livre des transactions pour l'administration.
     * Consommé par le composant Next.js 'TransactionTable'.
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);
        $transactions = $this->filteredQuery($request)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json($transactions, 200);
    }

    /**
     * Exporte le grand livre filtré au format Excel (.xlsx). Consommé par le
     * bouton "Exporter" du composant Next.js 'TransactionTable'.
     */
    public function exportExcel(Request $request)
    {
        $transactions = $this->filteredQuery($request)
            ->orderBy('created_at', 'desc')
            ->get();

        return Excel::download(
            new TransactionsExport($transactions),
            'transactions_' . now()->format('Y-m-d_His') . '.xlsx'
        );
    }

    /**
     * Exporte le grand livre filtré au format PDF. Consommé par le bouton
     * "Exporter" du composant Next.js 'TransactionTable'.
     */
    public function exportPdf(Request $request)
    {
        $transactions = $this->filteredQuery($request)
            ->orderBy('created_at', 'desc')
            ->get();

        $pdf = Pdf::loadView('exports.transactions-pdf', [
            'transactions' => $transactions,
            'dateFrom' => $request->input('date_from'),
            'dateTo' => $request->input('date_to'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('transactions_' . now()->format('Y-m-d_His') . '.pdf');
    }

    /**
     * Construit la requête filtrée (statut, type, opérateur, plage de dates)
     * partagée par la liste paginée et les deux exports.
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = Transaction::with([
            'user' => function ($q) {
                $q->select('id', 'name', 'phone', 'role');
            },
            'recipient' => function ($q) {
                $q->select('id', 'name', 'phone', 'operator');
            },
        ]);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('operator')) {
            $query->where('recipient_operator', $request->operator);
        }

        // Filtre par plage de dates (bornes incluses, sur la date de création)
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        return $query;
    }
}
