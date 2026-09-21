<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie les appels serveur-à-serveur des marchands via clé API
 * (Authorization: Bearer sk_live_xxx / sk_test_xxx, ou header X-API-Key), en
 * alternative aux tokens Sanctum utilisés par l'app Flutter et le dashboard.
 *
 * Une fois la clé résolue, Auth::setUser() authentifie le marchand exactement
 * comme auth:sanctum (Auth::user() / $request->user() fonctionnent
 * normalement), ce qui permet de réutiliser tel quel les contrôleurs
 * existants (TransferController, CountryController...) sur les routes
 * /api/v1/gateway/*.
 *
 * Le paramètre de middleware ($scope) restreint la route à un scope donné
 * (ex: 'transfer.write') : la clé doit l'avoir explicitement dans ApiKey::scopes.
 */
class ApiKeyAuth
{
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $plainTextKey = $request->bearerToken() ?? $request->header('X-API-Key');

        if (! $plainTextKey) {
            return response()->json([
                'status' => 'error',
                'message' => 'Clé API manquante.',
            ], 401);
        }

        $apiKey = ApiKey::findByPlainTextKey($plainTextKey);

        if (! $apiKey || ! $apiKey->user || ! $apiKey->user->status) {
            return response()->json([
                'status' => 'error',
                'message' => 'Clé API invalide ou révoquée.',
            ], 401);
        }

        if ($scope && ! $apiKey->hasScope($scope)) {
            return response()->json([
                'status' => 'error',
                'message' => "Cette clé API n'a pas la permission '{$scope}'.",
            ], 403);
        }

        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();

        Auth::setUser($apiKey->user);
        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }
}
