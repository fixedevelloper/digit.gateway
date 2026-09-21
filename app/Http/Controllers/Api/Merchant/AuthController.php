<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Auto-inscription et connexion des comptes marchands B2B (intégrateurs de la
 * passerelle, espace self-service SaaS). Distinct de Api\AuthController
 * (clients mobile money de l'app Flutter) : ici le compte créé a
 * role='merchant' et donne accès à la génération de clés API, pas à l'app
 * mobile.
 */
class AuthController extends Controller
{
    /**
     * Inscription self-service d'un marchand. Le compte démarre en sandbox et
     * actif immédiatement : la bascule en production reste une décision admin
     * (MerchantController@update), après vérification du dossier marchand.
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'company_name' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => 'required|string|unique:users,phone',
            'password' => 'required|string|min:8|confirmed',
        ], [
            'email.unique' => 'Cet email est déjà associé à un compte.',
            'phone.unique' => 'Ce numéro de téléphone est déjà associé à un compte.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $merchant = User::create([
            'name' => $request->name,
            'company_name' => $request->company_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'role' => 'merchant',
            'environment' => 'sandbox',
            'status' => true,
            // Non utilisé par les comptes marchands : aucune route /v1/gateway/* ne
            // vérifie de PIN (c'est la clé API elle-même qui fait office de secret).
            'transaction_pin' => Hash::make((string) Str::random(8)),
        ]);

        $token = $merchant->createToken('merchant_dashboard_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Compte marchand créé avec succès. Vous démarrez en environnement sandbox.',
            'token' => $token,
            'merchant' => $this->publicProfile($merchant),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $merchant = User::where('email', $request->email)->where('role', 'merchant')->first();

        if (! $merchant || ! Hash::check($request->password, $merchant->password)) {
            return response()->json([
                'status' => 'error',
                'message' => "L'email ou le mot de passe est incorrect.",
            ], 401);
        }

        if (! $merchant->status) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ce compte marchand est suspendu. Contactez le support.',
            ], 403);
        }

        $merchant->tokens()->delete();
        $token = $merchant->createToken('merchant_dashboard_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Connexion réussie',
            'token' => $token,
            'merchant' => $this->publicProfile($merchant),
        ], 200);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['status' => 'success', 'message' => 'Déconnexion réussie'], 200);
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'merchant' => $this->publicProfile($request->user()),
        ], 200);
    }

    private function publicProfile(User $merchant): array
    {
        return [
            'id' => $merchant->id,
            'name' => $merchant->name,
            'company_name' => $merchant->company_name,
            'email' => $merchant->email,
            'phone' => $merchant->phone,
            'environment' => $merchant->environment,
            'status' => $merchant->status,
            'created_at' => $merchant->created_at,
        ];
    }
}
