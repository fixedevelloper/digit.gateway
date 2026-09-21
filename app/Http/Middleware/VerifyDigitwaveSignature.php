<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie les notifications webhook entrantes de Digitwave (route publique,
 * hors auth:sanctum/auth.apikey) via la signature HMAC-SHA256 du corps brut de
 * la requête, comparée à l'en-tête X-Webhook-Signature. Comparaison en temps
 * constant (hash_equals) pour éviter une attaque par timing sur la signature.
 */
class VerifyDigitwaveSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.digitwave.webhook_secret');
        $signature = $request->header('X-Webhook-Signature');

        if (! $secret) {
            Log::error('[Webhook Digitwave] DIGITWAVE_WEBHOOK_SECRET non configuré : requête rejetée.');

            return response()->json(['status' => 'error', 'message' => 'Webhook non configuré.'], 500);
        }

        if (! $signature) {
            Log::warning('[Webhook Digitwave] Requête rejetée : en-tête X-Webhook-Signature absent.', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['status' => 'error', 'message' => 'Signature manquante.'], 401);
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            Log::warning('[Webhook Digitwave] Requête rejetée : signature invalide.', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['status' => 'error', 'message' => 'Signature invalide.'], 401);
        }

        return $next($request);
    }
}
