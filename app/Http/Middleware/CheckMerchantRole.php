<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restreint les routes de l'espace self-service SaaS aux comptes marchands.
 * Doit être placé après 'auth:sanctum'.
 */
class CheckMerchantRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'merchant') {
            return response()->json([
                'status' => 'error',
                'message' => 'Accès réservé aux comptes marchands.',
            ], 403);
        }

        return $next($request);
    }
}
