<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CountryController extends Controller
{
    /**
     * Liste tous les pays configurés sur la plateforme.
     * Consommé par l'interface d'administration Next.js.
     */
    public function index()
    {
        // Récupération des pays triés par nom
        $countries = Country::orderBy('name', 'asc')->get();

        // Si tu stockes un chemin relatif, tu peux injecter dynamiquement l'URL absolue ici
        $countries->transform(function ($country) {
            if ($country->flag && !str_starts_with($country->flag, 'http')) {
                $country->flag_url = asset('storage/' . $country->flag);
            } else {
                $country->flag_url = $country->flag;
            }
            return $country;
        });

        return response()->json($countries, 200);
    }

    /**
     * Ajoute un nouveau corridor / pays sur la plateforme avec son drapeau.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name'      => 'required|string|max:100|unique:countries,name',
            'iso'       => 'required|string|max:2|unique:countries,iso',
            'iso3'      => 'required|string|max:3|unique:countries,iso3',
            'phonecode' => 'required|string|max:10',
            'currency'  => 'required|string|max:10',
            'flag'      => 'nullable|image|mimes:jpeg,png,jpg,svg|max:2048', // Max 2Mo
        ]);

        // Gestion de l'upload de l'image du drapeau
        if ($request->hasFile('flag')) {
            // Stockage dans storage/app/public/flags
            $path = $request->file('flag')->store('flags', 'public');
            $validatedData['flag'] = $path;
        }

        // Par défaut, un nouveau corridor est actif à la création
        $validatedData['status'] = true;

        $country = Country::create($validatedData);

        // Ajout de l'URL absolue pour Next.js dans la réponse
        $country->flag_url = $country->flag ? asset('storage/' . $country->flag) : null;

        return response()->json([
            'status'  => 'success',
            'message' => "Le corridor {$country->name} a été créé avec succès.",
            'data'    => $country
        ], 201);
    }

    /**
     * Met à jour les paramètres globaux d'un pays (y compris son drapeau).
     */
    public function update(Request $request, $id)
    {
        $country = Country::findOrFail($id);

        // Validation des paramètres selon la structure de ta table
        $validatedData = $request->validate([
            'name'      => 'sometimes|string|max:100|unique:countries,name,' . $id,
            'iso'       => 'sometimes|string|max:2|unique:countries,iso,' . $id,
            'iso3'      => 'sometimes|string|max:3|unique:countries,iso3,' . $id,
            'status'    => 'sometimes|boolean',
            'phonecode' => 'sometimes|string|max:10',
            'currency'  => 'sometimes|string|max:10',
            'flag'      => 'nullable|image|mimes:jpeg,png,jpg,svg|max:2048',
        ]);

        // Gestion du téléversement du nouveau drapeau
        if ($request->hasFile('flag')) {
            // Suppression de l'ancien drapeau s'il existe pour éviter d'encombrer le serveur
            if ($country->flag) {
                Storage::disk('public')->delete($country->flag);
            }

            // Stockage du nouveau fichier
            $path = $request->file('flag')->store('flags', 'public');
            $validatedData['flag'] = $path;
        }

        // Mise à jour en base de données
        $country->update($validatedData);

        // Rafraîchissement de l'URL d'accès du drapeau
        $country->flag_url = $country->flag ? asset('storage/' . $country->flag) : null;

        return response()->json([
            'status'  => 'success',
            'message' => "Le corridor {$country->name} a été mis à jour avec succès.",
            'data'    => $country
        ], 200);
    }
}
