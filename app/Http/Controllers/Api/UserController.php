<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /**
     * Récupérer les informations du profil utilisateur connecté.
     * Route: GET /api/profile
     */
    public function getProfile(Request $request)
    {
        // Ton ApiClient s'attend à trouver l'utilisateur sous la clé 'user' ou à la racine
        return response()->json([
            'status' => 'success',
            'user' => $request->user(), // Retourne le UserModel complet
        ], 200);
    }

    /**
     * Mettre à jour les informations de base du profil.
     * Route: POST /api/profile/update
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        // Validation des données d'entrée
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            // Exemple de validation pour le Cameroun : unique excepté pour l'utilisateur actuel
            'phone' => 'required|string|max:20|unique:users,phone,' . $user->id,
        ], [
            'name.required' => 'Le nom est obligatoire.',
            'phone.required' => 'Le numéro de téléphone est obligatoire.',
            'phone.unique' => 'Ce numéro de téléphone est déjà associé à un autre compte.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors() // Capturé par _parseDioError dans Flutter
            ], 422);
        }

        // Sauvegarde
        $user->update([
            'name' => $request->name,
            'phone' => $request->phone,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Profil mis à jour avec succès.',
            'user' => $user
        ], 200);
    }

    /**
     * Mettre à jour le code PIN de transaction.
     * Route: POST /api/profile/update-pin
     */
    public function updateCodePin(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'old_pin' => 'required|digits:4',
            'pin' => 'required|digits:4|confirmed', // Vérifie automatiquement 'pin_confirmation'
        ], [
            'old_pin.required' => 'L\'ancien code PIN est requis.',
            'old_pin.digits' => 'L\'ancien code PIN doit contenir 4 chiffres.',
            'pin.required' => 'Le nouveau code PIN est requis.',
            'pin.digits' => 'Le nouveau code PIN doit contenir 4 chiffres.',
            'pin.confirmed' => 'Les deux codes PIN ne correspondent pas.',
        ]);

        logger('pesss');
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Vérification de la correspondance de l'ancien code PIN
        // Supposons que ton champ en base de données s'appelle 'transaction_pin' et soit haché
        if (!Hash::check($request->old_pin, $user->transaction_pin)) {
            return response()->json([
                'status' => 'error',
                'message' => 'L\'ancien code PIN saisi est incorrect.' // Capturé par data['message'] dans Flutter
            ], 400);
        }

        // Mise à jour du code PIN (haché pour la sécurité)
        $user->update([
            'transaction_pin' => Hash::make($request->pin)
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Code PIN de transaction modifié avec succès.'
        ], 200);
    }

    /**
     * Modifier le mot de passe de connexion principal.
     * Route: POST /api/profile/change-password
     */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed', // Vérifie automatiquement 'password_confirmation'
        ], [
            'current_password.required' => 'Votre mot de passe actuel est obligatoire.',
            'password.required' => 'Le nouveau mot de passe est obligatoire.',
            'password.min' => 'Le nouveau mot de passe doit contenir au moins 6 caractères.',
            'password.confirmed' => 'Les mots de passe ne correspondent pas.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Vérification de l'ancien mot de passe
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Le mot de passe actuel est incorrect.'
            ], 400);
        }

        // Sauvegarde du nouveau mot de passe haché
        $user->update([
            'password' => Hash::make($request->password)
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Mot de passe mis à jour avec succès.'
        ], 200);
    }
}