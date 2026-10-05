<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve une action sensible aux comptes qui ont activé la 2FA (config security.require_admin_2fa).
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('security.require_admin_2fa') && ! $request->user()?->hasTwoFactorEnabled()) {
            return response()->json([
                'status' => 'error',
                'error_code' => 'TWO_FACTOR_REQUIRED',
                'message' => 'Activez l\'authentification à deux facteurs sur votre compte pour effectuer cette action.',
            ], 403);
        }

        return $next($request);
    }
}
