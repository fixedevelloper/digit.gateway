<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vérifie le code PIN de transaction transmis dans la requête ('pin') contre
 * celui de l'utilisateur authentifié (Sanctum). Doit être placé après
 * 'auth:sanctum' dans la chaîne de middleware.
 *
 * Un PIN à 4 chiffres n'a que 10 000 valeurs : au-delà de MAX_ATTEMPTS erreurs,
 * toute vérification de PIN du compte est bloquée pendant LOCK_SECONDS (compteur
 * partagé avec le changement de PIN, cf. UserController::updateCodePin).
 */
class VerifyTransactionPin
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_SECONDS = 3600;

    public static function limiterKey(int $userId): string
    {
        return 'pin-attempts:'.$userId;
    }

    public static function lockedResponse(int $userId): Response
    {
        $minutes = (int) ceil(RateLimiter::availableIn(self::limiterKey($userId)) / 60);

        return response()->json([
            'status' => 'error',
            'message' => "Trop de codes PIN incorrects. Réessayez dans {$minutes} minute(s).",
        ], 429);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        $key = self::limiterKey($user->id);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return self::lockedResponse($user->id);
        }

        if (! Hash::check((string) $request->input('pin'), $user->transaction_pin)) {
            RateLimiter::hit($key, self::LOCK_SECONDS);

            return response()->json([
                'status' => 'error',
                'message' => 'Code PIN incorrect.',
            ], 403);
        }

        RateLimiter::clear($key);

        return $next($request);
    }
}
