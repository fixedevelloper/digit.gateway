<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteTransferRequest;
use App\Http\Requests\TransferReasonRequest;
use App\Http\Requests\UploadProofRequest;
use App\Http\Resources\AgentTransferResource;
use App\Models\Transaction;
use App\Services\ManualTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * File de traitement manuel et actions des agents. Routes protégées par 'agent.role' ;
 * chaque action est autorisée par TransferPolicy et validée par ManualTransferService.
 */
class TransferController extends Controller
{
    public function __construct(private readonly ManualTransferService $manual)
    {
    }

    /**
     * File des transferts manuels
     *
     * Par défaut les transferts en attente de prise en charge ; `status` filtre par statut,
     * `mine=1` limite aux transferts assignés à l'agent, `service` à MOBILE_MONEY/BANK_TRANSFER.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewQueue', Transaction::class);

        $transfers = Transaction::query()
            ->with(['user', 'destinationCountry', 'bankBeneficiary', 'assignedAgent'])
            ->where('processing_mode', 'MANUAL')
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_agent_id', $request->user()->id))
            ->when($request->query('service'), fn ($q, $service) => $q->where('service', $service))
            ->where('status', $request->query('status', Transaction::STATUS_PENDING_MANUAL_REVIEW))
            ->orderByRaw("priority = 'high' desc")
            ->oldest()
            ->paginate(20);

        return AgentTransferResource::collection($transfers);
    }

    public function show(Transaction $transfer): AgentTransferResource
    {
        Gate::authorize('view', $transfer);

        return new AgentTransferResource($transfer->load(['user', 'destinationCountry', 'bankBeneficiary', 'assignedAgent', 'proofs', 'auditLogs']));
    }

    /** Prendre en charge un transfert (PENDING_MANUAL_REVIEW → ASSIGNED). */
    public function claim(Request $request, Transaction $transfer): AgentTransferResource
    {
        Gate::authorize('claim', $transfer);

        return $this->respond($this->manual->claim($transfer, $request->user()));
    }

    /** Commencer le traitement (ASSIGNED → PROCESSING). */
    public function start(Request $request, Transaction $transfer): AgentTransferResource
    {
        Gate::authorize('process', $transfer);

        return $this->respond($this->manual->start($transfer, $request->user()));
    }

    /** Rendre un transfert pas encore commencé (ASSIGNED → PENDING_MANUAL_REVIEW). */
    public function release(Request $request, Transaction $transfer): AgentTransferResource
    {
        Gate::authorize('process', $transfer);

        return $this->respond($this->manual->release($transfer, $request->user()));
    }

    /** Valider le transfert (PROCESSING → COMPLETED), avec références, commentaire et au moins une preuve. */
    public function complete(CompleteTransferRequest $request, Transaction $transfer): AgentTransferResource
    {
        Gate::authorize('process', $transfer);

        return $this->respond($this->manual->complete($transfer, $request->user(), $request->validated()));
    }

    /** Rejeter le transfert (PROCESSING → REJECTED) : le client est remboursé. */
    public function reject(TransferReasonRequest $request, Transaction $transfer): AgentTransferResource
    {
        Gate::authorize('process', $transfer);

        return $this->respond($this->manual->reject($transfer, $request->user(), $request->validated('reason')));
    }

    /** Déclarer un échec (PROCESSING → FAILED) : le client est remboursé. */
    public function fail(TransferReasonRequest $request, Transaction $transfer): AgentTransferResource
    {
        Gate::authorize('process', $transfer);

        return $this->respond($this->manual->fail($transfer, $request->user(), $request->validated('reason')));
    }

    /** Déposer une preuve (PDF, JPG, JPEG, PNG — 5 Mo max). */
    public function proof(UploadProofRequest $request, Transaction $transfer): JsonResponse
    {
        Gate::authorize('process', $transfer);

        $proof = $this->manual->addProof($transfer, $request->user(), $request->file('proof'));

        return response()->json([
            'status' => 'success',
            'proof' => ['id' => $proof->id, 'file_name' => $proof->file_name, 'mime_type' => $proof->mime_type, 'size' => $proof->size],
        ], 201);
    }

    private function respond(Transaction $transfer): AgentTransferResource
    {
        return new AgentTransferResource($transfer->load(['user', 'destinationCountry', 'bankBeneficiary', 'assignedAgent', 'proofs']));
    }
}
