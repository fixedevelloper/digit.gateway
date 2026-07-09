<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Inscription d'un nouvel utilisateur (Register)
     */
    public function register(Request $request)
    {
        // 1. Validation des données d'entrée
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'phone' => 'required|string|unique:users,phone',
            'password' => 'required|string|min:6|confirmed',
        ], [
            'phone.unique' => 'Ce numéro de téléphone est déjà associé à un compte.',
            'password.min' => 'Le mot de passe doit contenir au moins 6 caractères.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        // 2. Création de l'utilisateur
        $user = User::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
             'transaction_pin' => Hash::make('0000')
        ]);

        // 3. Initialisation automatique de son portefeuille (Wallet)
        // Devise par défaut : XAF (Franc CFA) avec un solde initial de 0.00
        Wallet::create([
            'user_id' => $user->id,
            'balance' => 0.00,
            'currency' => 'XAF'
        ]);

        // 4. Génération du token de connexion Sanctum
        $token = $user->createToken('digit_gateway_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Compte créé avec succès',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'wallet' => $user->wallet // Inclus les détails du portefeuille créé
            ]
        ], 200);
    }

    /**
     * Connexion de l'utilisateur (Login)
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
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Le numéro de téléphone ou le mot de passe est incorrect.'
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
                'wallet' => $user->wallet
            ]
        ], 200);
    }

    /**
     * Déconnexion de l'utilisateur (Logout)
     */
    public function logout(Request $request)
    {
        // Révocation du token actuellement utilisé
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Déconnexion réussie'
        ], 200);
    }

    /**
     * Récupération des informations du profil connecté
     */
    public function profile(Request $request)
    {
        $user = User::with('wallet')->find($request->user()->id);

        return response()->json([
            'status' => 'success',
            'user' => $user
        ], 200);
    }
}