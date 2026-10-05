<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Security\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Gestion de la 2FA du compte connecté (console admin et portail marchand).
 * Chaque changement exige le mot de passe actuel : un token volé ne suffit pas à
 * désactiver ou remplacer la 2FA.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'status' => 'success',
            'data' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'recovery_codes_left' => $user->hasTwoFactorEnabled() ? count($user->two_factor_recovery_codes ?? []) : 0,
                'required_for_sensitive_actions' => (bool) config('security.require_admin_2fa'),
            ],
        ]);
    }

    /** Génère un secret (pas encore actif) : à scanner puis confirmer avec un premier code. */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate(['password' => 'required|string']);

        if (! Hash::check($request->password, $user->password)) {
            return $this->wrongPassword();
        }

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['status' => 'error', 'message' => 'La 2FA est déjà activée.'], 409);
        }

        $secret = $this->twoFactor->generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        return response()->json([
            'status' => 'success',
            'data' => ['secret' => $secret, 'otpauth_url' => $this->twoFactor->provisioningUri($user, $secret)],
        ]);
    }

    /** Active la 2FA après vérification d'un premier code ; renvoie les codes de secours (une seule fois). */
    public function confirm(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['code' => 'required|string|max:20']);

        if (! $user->two_factor_secret || $user->hasTwoFactorEnabled()) {
            return response()->json(['status' => 'error', 'message' => 'Aucune activation en cours.'], 409);
        }

        if (! $this->twoFactor->verifyCode($user, $data['code'])) {
            return response()->json(['status' => 'error', 'message' => 'Code incorrect.'], 422);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Authentification à deux facteurs activée.',
            'data' => ['recovery_codes' => $this->twoFactor->generateRecoveryCodes($user)],
        ]);
    }

    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'password' => 'required|string',
            'code' => 'nullable|string|max:20',
            'recovery_code' => 'nullable|string|max:20',
        ]);

        if (! Hash::check($data['password'], $user->password)) {
            return $this->wrongPassword();
        }

        if (! $user->hasTwoFactorEnabled()) {
            return response()->json(['status' => 'error', 'message' => 'La 2FA n\'est pas activée.'], 409);
        }

        if (! $this->twoFactor->verifyAny($user, $data['code'] ?? null, $data['recovery_code'] ?? null)) {
            return response()->json(['status' => 'error', 'message' => 'Code incorrect.'], 422);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        return response()->json(['status' => 'success', 'message' => 'Authentification à deux facteurs désactivée.']);
    }

    /** Remplace les codes de secours (les anciens cessent de fonctionner). */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['password' => 'required|string', 'code' => 'required|string|max:20']);

        if (! Hash::check($data['password'], $user->password)) {
            return $this->wrongPassword();
        }

        if (! $user->hasTwoFactorEnabled() || ! $this->twoFactor->verifyCode($user, $data['code'])) {
            return response()->json(['status' => 'error', 'message' => 'Code incorrect.'], 422);
        }

        return response()->json(['status' => 'success', 'data' => ['recovery_codes' => $this->twoFactor->generateRecoveryCodes($user)]]);
    }

    private function wrongPassword(): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => 'Mot de passe incorrect.'], 422);
    }
}
