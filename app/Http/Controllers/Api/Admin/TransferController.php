<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgentTransferResource;
use App\Models\Transaction;
use App\Services\ManualTransferService;
use Illuminate\Http\Request;

/**
 * Supervision des transferts (Mobile Money et bancaires) pour l'administration. Lecture
 * seule : le traitement relève des agents.
 */
class TransferController extends Controller
{
    private const WITH = ['user', 'destinationCountry', 'bankBeneficiary', 'assignedAgent'];

    /** Tous les transferts, filtrables par statut, service, mode et utilisateur. */
    public function index(Request $request)
    {
        return AgentTransferResource::collection(
            Transaction::with(self::WITH)
                ->where('type', 'transfer')
                ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
                ->when($request->query('service'), fn ($q, $v) => $q->where('service', $v))
                ->when($request->query('processing_mode'), fn ($q, $v) => $q->where('processing_mode', $v))
                ->when($request->query('user_id'), fn ($q, $v) => $q->where('user_id', $v))
                ->latest('id')
                ->paginate(30)
        );
    }

    /** File manuelle : transferts traités par les agents (par défaut tous statuts, `status` pour filtrer). */
    public function manual(Request $request)
    {
        return AgentTransferResource::collection(
            Transaction::with(self::WITH)
                ->where('processing_mode', 'MANUAL')
                ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
                ->when($request->query('assigned_agent_id'), fn ($q, $v) => $q->where('assigned_agent_id', $v))
                ->latest('id')
                ->paginate(30)
        );
    }

    /** Détail d'un transfert avec preuves et journal d'audit complet. */
    public function show(string $id): AgentTransferResource
    {
        return new AgentTransferResource(
            Transaction::with([...self::WITH, 'proofs', 'auditLogs'])->where('type', 'transfer')->findOrFail($id)
        );
    }

    /**
     * Remet dans la file un transfert manuel bloqué (agent absent ou suspendu) : `assigned` ou
     * `processing` → `pending_manual_review`, motif obligatoire (tracé dans l'audit).
     */
    public function release(Request $request, string $id, ManualTransferService $manual): AgentTransferResource
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        $transfer = Transaction::where('type', 'transfer')->findOrFail($id);

        return new AgentTransferResource(
            $manual->release($transfer, $request->user(), $data['reason'])->load([...self::WITH, 'proofs', 'auditLogs'])
        );
    }
}
