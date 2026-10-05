<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SecurityController extends Controller
{
    /**
     * Authentification de l'administrateur et génération du jeton de session.
     *
     * @return JsonResponse
     *
     * @throws ValidationException
     */
    public function login(Request $request)
    {
        // 1. Validation stricte des entrées (Format téléphone et mot de passe)
        $credentials = $request->validate([
            'phone' => 'required|string',
            'password' => 'required|string|min:6',
        ]);

        // 2. Recherche de l'utilisateur par son numéro de téléphone
        $user = User::where('phone', $credentials['phone'])->first();

        // 3. Vérification des identifiants et restriction stricte aux administrateurs
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'phone' => ['Les identifiants fournis sont incorrects.'],
            ]);
        }

        // 4. Barrière de sécurité : Vérification du privilège d'administration
        // (Adapte cette ligne selon ta structure de rôles : $user->is_admin ou un package comme Spatie Roles)
        if ($user->role !== 'admin' && $user->role !== 'superadmin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Accès refusé. Cette console est strictement réservée aux administrateurs.',
            ], 403);
        }

        if (! $user->status) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ce compte administrateur est suspendu.',
            ], 403);
        }

        // 5. 2FA activée : le mot de passe seul ne donne pas de token, il ouvre un défi à usage limité.
        if ($user->hasTwoFactorEnabled()) {
            return response()->json([
                'status' => 'two_factor_required',
                'challenge' => app(TwoFactorService::class)->issueChallenge($user, 'admin'),
            ], 200);
        }

        return $this->session($user);
    }

    /**
     * Seconde étape de connexion : code TOTP (ou code de secours) + jeton de défi.
     *
     * URL: POST /api/admin/auth/2fa
     */
    public function twoFactor(Request $request, TwoFactorService $twoFactor)
    {
        $data = $request->validate([
            'challenge' => 'required|string',
            'code' => 'nullable|string|max:20',
            'recovery_code' => 'nullable|string|max:20',
        ]);

        $user = $twoFactor->resolveChallenge($data['challenge'], 'admin', $data['code'] ?? null, $data['recovery_code'] ?? null);

        if (! $user || ! in_array($user->role, ['admin', 'superadmin'], true)) {
            return response()->json(['status' => 'error', 'message' => 'Code invalide ou expiré.'], 422);
        }

        return $this->session($user);
    }

    private function session(User $user): JsonResponse
    {
        // Génération du Token avec capacités définies (Laravel Sanctum)
        $tokenCapabilities = $user->role === 'superadmin' ? ['*'] : ['gateways:read', 'transactions:manage'];
        $token = $user->createToken('digit_gateway_admin_token', $tokenCapabilities)->plainTextToken;

        // Réponse structurée consommée par notre interceptor Axios Next.js
        return response()->json([
            'status' => 'success',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'role' => $user->role,
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            ],
        ], 200);
    }

    /**
     * Révocation du jeton et fermeture sécurisée de la session.
     */
    public function logout(Request $request)
    {
        // Révocation du token qui a initié la requête actuelle
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Session d\'administration fermée et jeton révoqué avec succès.',
        ], 200);
    }
}
