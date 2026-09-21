<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gestion des clés API du marchand connecté (espace self-service SaaS). La
 * valeur en clair n'est retournée qu'à la création (store) ; toute autre
 * lecture ne renvoie que le préfixe (key_prefix).
 */
class ApiKeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $keys = $request->user()->apiKeys()
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'key_prefix', 'environment', 'scopes', 'last_used_at', 'revoked_at', 'created_at']);

        return response()->json(['status' => 'success', 'data' => $keys], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $merchant = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'environment' => ['required', Rule::in(['sandbox', 'production'])],
            'scopes' => 'required|array|min:1',
            'scopes.*' => [Rule::in(ApiKey::SCOPES)],
        ]);

        // La production n'est ouverte qu'aux marchands déjà validés par un admin
        // (bascule environment='production' via Admin\MerchantController@update).
        if ($validated['environment'] === 'production' && $merchant->environment !== 'production') {
            return response()->json([
                'status' => 'error',
                'message' => "Votre compte n'est pas encore activé en production. Contactez le support.",
            ], 403);
        }

        ['model' => $apiKey, 'plainTextKey' => $plainTextKey] = ApiKey::generateFor(
            $merchant,
            $validated['name'],
            $validated['environment'],
            $validated['scopes'],
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Clé API générée. Copiez-la maintenant : elle ne sera plus jamais affichée en clair.',
            'data' => [
                'id' => $apiKey->id,
                'name' => $apiKey->name,
                'environment' => $apiKey->environment,
                'scopes' => $apiKey->scopes,
                'key' => $plainTextKey,
            ],
        ], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $apiKey = $request->user()->apiKeys()->findOrFail($id);
        $apiKey->update(['revoked_at' => now()]);

        return response()->json(['status' => 'success', 'message' => 'Clé API révoquée.'], 200);
    }
}
