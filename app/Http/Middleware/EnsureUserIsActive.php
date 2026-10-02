<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse toute requête d'un compte suspendu (status = false), même avec un token
 * Sanctum encore valide. Doit être placé après 'auth:sanctum'.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->status) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ce compte est suspendu. Contactez le support.',
            ], 403);
        }

        return $next($request);
    }
}
