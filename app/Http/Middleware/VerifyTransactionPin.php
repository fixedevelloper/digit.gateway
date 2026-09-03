<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vérifie le code PIN de transaction transmis dans la requête ('pin') contre
 * celui de l'utilisateur authentifié (Sanctum). Doit être placé après
 * 'auth:sanctum' dans la chaîne de middleware.
 */
class VerifyTransactionPin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Hash::check((string) $request->input('pin'), Auth::user()->transaction_pin)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Code PIN incorrect.',
            ], 403);
        }

        return $next($request);
    }
}
