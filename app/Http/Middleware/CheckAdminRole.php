<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Vérification stricte selon les rôles définis dans ta migration
        if (!$user || !in_array($user->role, ['admin', 'superadmin'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Accès interdit. Privilèges administratifs requis.'
            ], 403);
        }

        return $next($request);
    }
}
