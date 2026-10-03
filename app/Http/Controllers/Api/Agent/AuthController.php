<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Connexion de la console agent (dashboard web). Distincte du login admin, qui refuse
 * les agents : un agent n'obtient qu'un jeton réservé aux routes /agent/*.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'phone' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('phone', $credentials['phone'])->where('role', 'agent')->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['phone' => ['Les identifiants fournis sont incorrects.']]);
        }

        if (! $user->status) {
            return response()->json(['status' => 'error', 'message' => 'Ce compte agent est suspendu.'], 403);
        }

        return response()->json([
            'status' => 'success',
            'token' => $user->createToken('digit_gateway_agent_token')->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name, 'phone' => $user->phone, 'role' => $user->role],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['status' => 'success']);
    }
}
