<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KycSubmission;
use App\Services\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * KYC du client connecté : niveau, plafonds, dépôt des pièces et suivi de la demande.
 */
class KycController extends Controller
{
    public function show(Request $request, KycService $kyc): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'status' => 'success',
            'data' => $kyc->summary($user) + [
                'latest_submission' => KycSubmission::where('user_id', $user->id)->latest('id')->first(),
            ],
        ]);
    }

    public function submit(Request $request, KycService $kyc): JsonResponse
    {
        $user = $request->user();
        $level3 = (int) $request->input('target_level') === 3;

        $data = $request->validate([
            'target_level' => 'required|integer|in:2,3',
            'document_type' => 'required|string|in:national_id,passport,driver_license,proof_of_address',
            'document_number' => $level3 ? 'nullable|string|max:100' : 'required|string|max:100',
            'full_name' => 'required|string|max:255',
            'birth_date' => $level3 ? 'nullable|date|before:today' : 'required|date|before:-16 years',
            // Niveau 2 : recto (+ verso) et selfie ; niveau 3 : justificatif de domicile.
            'front' => ($level3 ? 'nullable' : 'required').'|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'back' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'selfie' => ($level3 ? 'nullable' : 'required').'|file|mimes:jpg,jpeg,png|max:5120',
            'proof' => ($level3 ? 'required' : 'nullable').'|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $files = array_filter($request->only(['front', 'back', 'selfie', 'proof']));

        try {
            $submission = $kyc->submit($user, $data, $files);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'success', 'message' => 'Demande envoyée, vérification en cours.', 'data' => $submission], 201);
    }
}
