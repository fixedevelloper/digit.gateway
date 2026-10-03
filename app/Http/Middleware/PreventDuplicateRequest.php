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

    /** Scopes dont le service métier déduplique via `transactions.idempotency_key`. */
    private const SCOPES_WITH_DURABLE_KEY = ['transfer', 'bank_transfer'];

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        // Une clé d'idempotence explicite est gérée durablement en base par le service métier,
        // mais seulement pour ces opérations : retrait et dépôt gardent le verrou de 15 s.
        if (trim((string) $request->header('Idempotency-Key')) !== '' && in_array($scope, self::SCOPES_WITH_DURABLE_KEY, true)) {
            return $next($request);
        }

        $fingerprint = sprintf(
            'idemp:%s:%d:%s',
            $scope,
            Auth::id(),
            md5((string) json_encode($request->only(['country', 'carrier', 'currency', 'operator_id', 'quote_id', 'number', 'amount', 'agensic_code', 'beneficiary'])))
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
