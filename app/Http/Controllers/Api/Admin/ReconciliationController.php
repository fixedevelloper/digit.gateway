<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\ReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * File de rapprochement : transactions dont l'issue chez Digitwave est inconnue.
 */
class ReconciliationController extends Controller
{
    public function index(ReconciliationService $reconciliation): JsonResponse
    {
        $page = $reconciliation->query()
            ->with('user:id,name,email,phone,role')
            ->orderBy('submitted_at')
            ->paginate(30)
            ->through(fn (Transaction $t) => array_merge(
                $t->makeVisible(Transaction::INTERNAL_FIELDS)->toArray(),
                ['reconciliation_reason' => $reconciliation->reasonFor($t)],
            ));

        return response()->json($page);
    }

    /**
     * Tranche une transaction après vérification chez Digitwave. `success` : le
     * bénéficiaire a été payé (aucun remboursement) ; `failed` : rien n'est parti
     * (remboursement du wallet). Réservé au superadmin : action financière.
     */
    public function resolve(Request $request, string $id, ReconciliationService $reconciliation): JsonResponse
    {
        $data = $request->validate([
            'outcome' => 'required|in:success,failed',
            'note' => 'required|string|min:5|max:1000',
        ]);

        $transaction = $reconciliation->query()->findOrFail($id);

        try {
            $resolved = $reconciliation->resolve($transaction, $data['outcome'], $request->user(), $data['note']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 409);
        }

        return response()->json([
            'status' => 'success',
            'data' => $resolved->makeVisible(Transaction::INTERNAL_FIELDS),
        ]);
    }
}
