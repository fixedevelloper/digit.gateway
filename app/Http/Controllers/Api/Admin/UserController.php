<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Gestion des utilisateurs mobile money (clients finaux de l'app Flutter) : un
 * utilisateur standard est un User avec role='customer', distinct des comptes
 * marchands B2B gérés par MerchantController.
 */
class UserController extends Controller
{
    /**
     * Liste les utilisateurs avec leur solde. Consommé par le composant
     * Next.js 'UsersPage' (Gestion des Utilisateurs).
     */
    public function index()
    {
        $users = User::where('role', 'customer')
            ->with('wallet:id,user_id,balance,currency')
            ->latest()
            ->get(['id', 'name', 'phone', 'email', 'status', 'created_at']);

        return response()->json($users, 200);
    }

    /**
     * Met à jour un compte utilisateur : suspension (révocation d'accès) ou
     * correction des informations de contact.
     */
    public function update(Request $request, string $id)
    {
        $user = User::where('role', 'customer')->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|nullable|string|max:255',
            'status' => 'sometimes|boolean',
        ]);

        $user->update($validated);
        $user->load('wallet:id,user_id,balance,currency');

        return response()->json([
            'status' => 'success',
            'message' => 'Compte utilisateur mis à jour avec succès.',
            'data' => $user,
        ], 200);
    }

    /**
     * Génère un nouveau mot de passe temporaire pour un utilisateur qui l'a
     * oublié (demande reçue par l'admin via WhatsApp depuis l'app Flutter).
     * Le mot de passe en clair n'est retourné qu'une seule fois dans cette
     * réponse : à l'admin de le transmettre lui-même à l'utilisateur.
     */
    public function generatePassword(string $id)
    {
        $user = User::where('role', 'customer')->findOrFail($id);

        // Mot de passe lisible à l'oral/dicté par téléphone : uniquement des
        // majuscules et chiffres, sans les caractères ambigus (0/O, 1/I).
        $charset = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = collect(range(1, 8))
            ->map(fn () => $charset[random_int(0, strlen($charset) - 1)])
            ->implode('');

        // Le cast 'hashed' du modèle User se charge du hachage à la sauvegarde.
        $user->password = $password;
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Nouveau mot de passe généré avec succès.',
            'password' => $password,
            'phone' => $user->phone,
        ], 200);
    }
}
