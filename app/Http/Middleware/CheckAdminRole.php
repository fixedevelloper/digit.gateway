<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAdminRole
{
    /**
     * Sans paramètre : admin ou superadmin. Avec paramètre (ex: 'admin.role:superadmin') :
     * uniquement les rôles listés.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $roles = $roles ?: ['admin', 'superadmin'];

        // Vérification stricte selon les rôles définis dans ta migration
        if (! $user || ! in_array($user->role, $roles, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Accès interdit. Privilèges administratifs requis.',
            ], 403);
        }

        return $next($request);
    }
}
