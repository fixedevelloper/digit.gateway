<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MerchantDocument;
use App\Models\MerchantKybEvent;
use App\Models\User;
use App\Services\MerchantKybService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Examen des dossiers marchands (KYB) par l'équipe : pièces, approbation, refus, audit.
 */
class MerchantKybController extends Controller
{
    public function __construct(private readonly MerchantKybService $kyb) {}

    public function show(string $id): JsonResponse
    {
        $merchant = $this->merchant($id);

        return response()->json(['status' => 'success', 'data' => $this->kyb->overview($merchant) + [
            'merchant' => $merchant->only(['id', 'name', 'company_name', 'email', 'phone', 'environment', 'status']),
            'events' => MerchantKybEvent::with('actor:id,name,role')->where('user_id', $merchant->id)->latest('id')->limit(100)->get(),
        ]]);
    }

    /** Téléchargement d'une pièce : chaque consultation est journalisée. */
    public function file(Request $request, string $id, string $documentId)
    {
        $document = MerchantDocument::where('user_id', $this->merchant($id)->id)->findOrFail($documentId);
        $disk = Storage::disk($document->disk);

        abort_unless($disk->exists($document->path), 404);

        $this->kyb->logView($document->setRelation('user', $document->user), $request->user());

        return $disk->download($document->path, $document->original_name);
    }

    /**
     * Dépôt d'une pièce par l'équipe pour le compte du marchand (documents reçus par e-mail, en agence...).
     * La pièce reste « en attente » de validation ; l'origine est obligatoire et tracée dans le journal.
     */
    public function uploadDocument(Request $request, string $id): JsonResponse
    {
        $merchant = $this->merchant($id);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys($this->kyb->documentTypes()))],
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:'.config('kyb.max_file_kb'),
            'expires_at' => 'nullable|date|after:today',
            'comment' => 'required|string|min:3|max:255',
        ]);

        return $this->guard(fn () => $this->kyb->upload($merchant, $data['type'], $request->file('file'), $data['expires_at'] ?? null, $request->user(), $data['comment']), 201);
    }

    /** Saisie des informations d'entreprise par l'équipe pour le compte du marchand. */
    public function saveProfile(Request $request, string $id): JsonResponse
    {
        $merchant = $this->merchant($id);
        $data = $request->validate([
            'registration_number' => 'required|string|max:100',
            'tax_id' => 'required|string|max:100',
            'country' => 'required|string|max:100',
            'address' => 'required|string|max:500',
            'business_description' => 'required|string|max:2000',
            'expected_monthly_volume' => 'required|numeric|min:0',
            'comment' => 'required|string|min:3|max:255',
        ]);
        $comment = $data['comment'];
        unset($data['comment']);

        return $this->guard(fn () => $this->kyb->saveProfile($merchant, $data, $request->user(), $comment));
    }

    public function approveDocument(Request $request, string $id, string $documentId): JsonResponse
    {
        return $this->review($request, $id, $documentId, true);
    }

    public function rejectDocument(Request $request, string $id, string $documentId): JsonResponse
    {
        return $this->review($request, $id, $documentId, false);
    }

    /** Approbation finale : réservée au superadmin avec 2FA (route). */
    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->guard(function () use ($request, $id) {
            $this->kyb->approveDossier($merchant = $this->merchant($id), $request->user());

            return $merchant->fresh();
        });
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:255']);

        return $this->guard(function () use ($request, $id, $data) {
            $this->kyb->rejectDossier($merchant = $this->merchant($id), $request->user(), $data['reason']);

            return $merchant->fresh();
        });
    }

    private function review(Request $request, string $id, string $documentId, bool $approve): JsonResponse
    {
        $reason = $approve ? null : $request->validate(['reason' => 'required|string|min:3|max:255'])['reason'];
        $document = MerchantDocument::with('user')->where('user_id', $this->merchant($id)->id)->findOrFail($documentId);

        return $this->guard(fn () => $this->kyb->reviewDocument($document, $request->user(), $approve, $reason));
    }

    private function merchant(string $id): User
    {
        return User::where('role', 'merchant')->findOrFail($id);
    }

    private function guard(callable $action, int $status = 200): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => $action()], $status);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 409);
        }
    }
}
