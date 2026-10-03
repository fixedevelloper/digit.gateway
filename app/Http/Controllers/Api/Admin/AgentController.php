<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ManualTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Comptes agents (role='agent') qui traitent les transferts manuels. Seul l'admin les crée,
 * les suspend ou réinitialise leur mot de passe ; un agent ne peut modifier aucun compte.
 */
class AgentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            User::where('role', 'agent')->latest()->get(['id', 'name', 'phone', 'email', 'status', 'created_at'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|unique:users,phone',
            'email' => 'nullable|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $agent = new User($data);
        $agent->role = 'agent';
        $agent->status = true;
        // La colonne transaction_pin est obligatoire mais inutile pour un agent (aucun paiement) : valeur aléatoire.
        $agent->transaction_pin = Str::random(32);
        $agent->save();

        return response()->json(['status' => 'success', 'data' => $agent->only(['id', 'name', 'phone', 'email', 'status'])], 201);
    }

    public function update(Request $request, string $id, ManualTransferService $manual): JsonResponse
    {
        $agent = User::where('role', 'agent')->findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|boolean',
            'password' => 'sometimes|string|min:8',
        ]);

        $agent->update($data);

        $suspended = array_key_exists('status', $data) && ! $data['status'];

        // Suspension ou nouveau mot de passe : les sessions ouvertes ne doivent pas survivre.
        if ($suspended || array_key_exists('password', $data)) {
            $agent->tokens()->delete();
        }

        // Suspension : ses transferts pas commencés retournent dans la file ; ceux en traitement
        // sont signalés (`processing_transfers`) pour une décision de l'admin (release).
        $queue = $suspended ? $manual->releaseAssignedTo($agent, $request->user()) : null;

        return response()->json([
            'status' => 'success',
            'data' => $agent->only(['id', 'name', 'phone', 'email', 'status']),
            'released_transfers' => $queue['released'] ?? 0,
            'processing_transfers' => $queue['processing'] ?? 0,
        ]);
    }
}
