<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SecurityController extends Controller
{
    /**
     * Authentification de l'administrateur et génération du jeton de session.
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws ValidationException
     */
    public function login(Request $request)
    {
        logger($request->all());
        // 1. Validation stricte des entrées (Format téléphone et mot de passe)
        $credentials = $request->validate([
            'phone'    => 'required|string',
            'password' => 'required|string|min:6',
        ]);

        logger($credentials);
        // 2. Recherche de l'utilisateur par son numéro de téléphone
        $user = User::where('phone', $credentials['phone'])->first();

        // 3. Vérification des identifiants et restriction stricte aux administrateurs
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'phone' => ['Les identifiants fournis sont incorrects.'],
            ]);
        }

        // 4. Barrière de sécurité : Vérification du privilège d'administration
        // (Adapte cette ligne selon ta structure de rôles : $user->is_admin ou un package comme Spatie Roles)
        if ($user->role !== 'admin' && $user->role !== 'superadmin') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Accès refusé. Cette console est strictement réservée aux administrateurs.'
            ], 403);
        }

        // 5. Génération du Token avec capacités définies (Laravel Sanctum)
        $tokenCapabilities = $user->role === 'superadmin' ? ['*'] : ['gateways:read', 'transactions:manage'];
        $token = $user->createToken('digit_gateway_admin_token', $tokenCapabilities)->plainTextToken;

        // 6. Réponse structurée consommée par notre interceptor Axios Next.js
        return response()->json([
            'status'  => 'success',
            'token'   => $token,
            'user'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'phone' => $user->phone,
                'role'  => $user->role
            ]
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
            'status'  => 'success',
            'message' => 'Session d\'administration fermée et jeton révoqué avec succès.'
        ], 200);
    }
}
