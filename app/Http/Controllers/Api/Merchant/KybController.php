<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Controller;
use App\Models\MerchantDocument;
use App\Services\MerchantKybService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Dossier de vérification du marchand connecté (portail) : informations d'entreprise, pièces, soumission.
 */
class KybController extends Controller
{
    public function __construct(private readonly MerchantKybService $kyb) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->kyb->overview($request->user())]);
    }

    public function saveProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registration_number' => 'required|string|max:100',
            'tax_id' => 'required|string|max:100',
            'country' => 'required|string|max:100',
            'address' => 'required|string|max:500',
            'business_description' => 'required|string|max:2000',
            'expected_monthly_volume' => 'required|numeric|min:0',
        ]);

        return $this->guard(fn () => ['profile' => $this->kyb->saveProfile($request->user(), $data)]);
    }

    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys($this->kyb->documentTypes()))],
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:'.config('kyb.max_file_kb'),
            'expires_at' => 'nullable|date|after:today',
        ]);

        $document = null;
        $response = $this->guard(function () use ($request, $data, &$document) {
            $document = $this->kyb->upload($request->user(), $data['type'], $request->file('file'), $data['expires_at'] ?? null);

            return ['document' => $document];
        }, 201);

        return $response;
    }

    public function submit(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $this->kyb->submit($request->user());

            return ['message' => 'Dossier soumis : il va être examiné par notre équipe.'];
        });
    }

    /** Le marchand peut relire ses propres pièces (jamais celles d'un autre). */
    public function file(Request $request, string $id)
    {
        $document = MerchantDocument::where('user_id', $request->user()->id)->findOrFail($id);
        $disk = Storage::disk($document->disk);

        abort_unless($disk->exists($document->path), 404);

        return $disk->download($document->path, $document->original_name);
    }

    private function guard(callable $action, int $status = 200): JsonResponse
    {
        try {
            return response()->json(['status' => 'success'] + $action(), $status);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }
}
