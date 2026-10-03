<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restreint les routes de traitement manuel aux agents. Doit être placé après 'auth:sanctum'.
 */
class CheckAgentRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'agent') {
            return response()->json([
                'status' => 'error',
                'message' => 'Accès réservé aux agents.',
            ], 403);
        }

        return $next($request);
    }
}
