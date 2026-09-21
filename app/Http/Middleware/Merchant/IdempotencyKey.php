<?php

namespace App\Http\Middleware\Merchant;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotence côté marchand (serveur-à-serveur) : le client fournit sa propre
 * clé via l'en-tête `Idempotency-Key`. La première requête est traitée
 * normalement et sa réponse mémorisée 24h ; toute requête rejouée avec la
 * même clé (retry réseau, timeout côté client) reçoit la réponse d'origine
 * sans que la logique métier ne s'exécute une seconde fois.
 *
 * Remplace, pour les routes /v1/gateway/*, le middleware 'idempotent' de
 * l'app mobile (fenêtre de 15s basée sur une empreinte des paramètres) : plus
 * robuste pour du serveur-à-serveur où le client contrôle explicitement la clé.
 *
 * Doit être placé après 'auth.apikey' dans la chaîne de middleware (a besoin
 * du marchand authentifié via $request->user()).
 */
class IdempotencyKey
{
    private const TTL_HOURS = 24;

    private const LOCK_SECONDS = 10;

    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! $idempotencyKey) {
            return response()->json([
                'status' => 'error',
                'error_code' => 'MISSING_IDEMPOTENCY_KEY',
                'message' => "L'en-tête Idempotency-Key est requis sur cet endpoint.",
            ], 400);
        }

        $merchantId = $request->user()->id;
        $cacheKey = "idempotency:{$merchantId}:{$idempotencyKey}";
        $requestHash = hash('sha256', $request->getContent());

        // Verrou court pour empêcher deux requêtes concurrentes portant la même clé
        // d'exécuter la logique métier en double (retry réseau envoyé deux fois en
        // parallèle) avant que la première réponse n'ait pu être mémorisée.
        $lock = Cache::lock("lock:{$cacheKey}", self::LOCK_SECONDS);

        try {
            $lock->block(5);

            $cached = Cache::get($cacheKey);

            if ($cached) {
                if ($cached['request_hash'] !== $requestHash) {
                    return response()->json([
                        'status' => 'error',
                        'error_code' => 'IDEMPOTENCY_KEY_REUSED',
                        'message' => "Cette clé d'idempotence a déjà été utilisée avec un corps de requête différent.",
                    ], 422);
                }

                return response()->json($cached['body'], $cached['status'])
                    ->header('Idempotency-Replayed', 'true');
            }

            $response = $next($request);

            // Ne mémorise que les réponses définitives : une 5xx est potentiellement
            // transitoire, on ne veut pas figer une erreur serveur pendant 24h.
            if ($response->getStatusCode() < 500) {
                Cache::put($cacheKey, [
                    'request_hash' => $requestHash,
                    'status' => $response->getStatusCode(),
                    'body' => json_decode($response->getContent(), true),
                ], now()->addHours(self::TTL_HOURS));
            }

            return $response;
        } finally {
            optional($lock)->release();
        }
    }
}
