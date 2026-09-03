<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Inscription d'un nouvel utilisateur (Register)
     *
     * @return JsonResponse
     */
    public function register(Request $request)
    {
        // 1. Validation des données d'entrée
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'phone' => 'required|string|unique:users,phone',
            'password' => 'required|string|min:6|confirmed',
            'pin' => 'required|digits:4',
        ], [
            'phone.unique' => 'Ce numéro de téléphone est déjà associé à un compte.',
            'password.min' => 'Le mot de passe doit contenir au moins 6 caractères.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'pin.required' => 'Le code PIN de transaction est obligatoire.',
            'pin.digits' => 'Le code PIN doit contenir exactement 4 chiffres.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        // 2. Création de l'utilisateur
        // Le portefeuille (Wallet) est créé automatiquement par l'événement User::booted().
        $user = User::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'transaction_pin' => Hash::make($request->pin),
        ]);

        // 3. Génération du token de connexion Sanctum
        $token = $user->createToken('digit_gateway_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Compte créé avec succès',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'wallet' => $user->wallet, // Inclus les détails du portefeuille créé
            ],
        ], 200);
    }

    /**
     * Connexion de l'utilisateur (Login)
     *
     * @return JsonResponse
     */
    public function login(Request $request)
    {
        // 1. Validation de la saisie
        $request->validate([
            'phone' => 'required|string',
            'password' => 'required|string',
        ]);

        // 2. Recherche de l'utilisateur par son numéro de téléphone
        $user = User::with('wallet')->where('phone', $request->phone)->first();

        // 3. Vérification des identifiants
        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Le numéro de téléphone ou le mot de passe est incorrect.',
            ], 401);
        }

        // 4. Remplacement ou génération du nouveau token Sanctum
        // (Optionnel : vous pouvez nettoyer les anciens tokens si nécessaire)
        $user->tokens()->delete();
        $token = $user->createToken('digit_gateway_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Connexion réussie',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'wallet' => $user->wallet,
            ],
        ], 200);
    }

    /**
     * Déconnexion de l'utilisateur (Logout)
     *
     * @return JsonResponse
     */
    public function logout(Request $request)
    {
        // Révocation du token actuellement utilisé
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Déconnexion réussie',
        ], 200);
    }

    /**
     * Récupération des informations du profil connecté
     *
     * @return JsonResponse
     */
    public function profile(Request $request)
    {
        $user = User::with('wallet')->find($request->user()->id);

        return response()->json([
            'status' => 'success',
            'user' => $user,
        ], 200);
    }
}
