<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Empêche le traitement de deux requêtes identiques (mêmes paramètres) soumises
 * par le même utilisateur en l'espace de quelques secondes (double-tap, retry
 * réseau côté client Flutter). Le "scope" (ex: 'transfer', 'withdrawal') isole
 * le verrou entre les différents types d'opérations.
 */
class PreventDuplicateRequest
{
    private const LOCK_SECONDS = 15;

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $fingerprint = sprintf(
            'idemp:%s:%d:%s',
            $scope,
            Auth::id(),
            md5((string) json_encode($request->only(['country', 'carrier', 'number', 'amount', 'agensic_code'])))
        );

        if (! Cache::add($fingerprint, true, now()->addSeconds(self::LOCK_SECONDS))) {
            return response()->json([
                'status' => 'error',
                'message' => 'Une demande identique est déjà en cours de traitement. Merci de patienter quelques secondes.',
            ], 409);
        }

        return $next($request);
    }
}
