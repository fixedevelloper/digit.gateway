<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankBeneficiary;
use App\Models\Country;
use App\Models\CountryService;
use App\Models\FeeRule;
use App\Models\Operator;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CountryController extends Controller
{
    /**
     * Liste tous les pays configurés sur la plateforme.
     * Consommé par l'interface d'administration Next.js.
     */
    public function index()
    {
        // Récupération des pays triés par nom
        $countries = Country::with(['forcedOperator', 'services'])->orderBy('name', 'asc')->get();

        // Si tu stockes un chemin relatif, tu peux injecter dynamiquement l'URL absolue ici
        $countries->transform(function ($country) {
            if ($country->flag && ! str_starts_with($country->flag, 'http')) {
                $country->flag_url = asset('storage/'.$country->flag);
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
        $this->normalizeCodes($request);

        $validatedData = $request->validate([
            'name' => 'required|string|max:100|unique:countries,name',
            'iso' => 'required|string|size:2|unique:countries,iso',
            'iso3' => 'required|string|size:3|unique:countries,iso3',
            'phonecode' => 'required|string|max:10',
            'currency' => 'required|string|max:10',
            // Pas de règle 'image' : elle rejette catégoriquement les SVG (protection XSS
            // intégrée à Laravel), en contradiction avec 'mimes' qui les autorise explicitement.
            'flag' => 'nullable|mimes:jpeg,png,jpg,svg|max:2048', // Max 2Mo
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
        $country->flag_url = $country->flag ? asset('storage/'.$country->flag) : null;

        return response()->json([
            'status' => 'success',
            'message' => "Le corridor {$country->name} a été créé avec succès.",
            'data' => $country,
        ], 201);
    }

    /**
     * Met à jour les paramètres globaux d'un pays (y compris son drapeau).
     */
    public function update(Request $request, $id)
    {
        $country = Country::findOrFail($id);

        $this->normalizeCodes($request);

        // Un envoi via FormData transmet "" plutôt que null quand le select est vidé
        if ($request->has('forced_operator_id') && $request->input('forced_operator_id') === '') {
            $request->merge(['forced_operator_id' => null]);
        }

        // Validation des paramètres selon la structure de ta table
        $validatedData = $request->validate([
            'name' => 'sometimes|string|max:100|unique:countries,name,'.$id,
            'iso' => 'sometimes|string|size:2|unique:countries,iso,'.$id,
            'iso3' => 'sometimes|string|size:3|unique:countries,iso3,'.$id,
            'status' => 'sometimes|boolean',
            'phonecode' => 'sometimes|string|max:10',
            'currency' => 'sometimes|string|max:10',
            'flag' => 'nullable|mimes:jpeg,png,jpg,svg|max:2048',
            'forced_operator_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('operators', 'id')->where('country_id', $id),
            ],
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

        // Rafraîchissement de l'URL d'accès du drapeau et de l'opérateur forcé
        $country->flag_url = $country->flag ? asset('storage/'.$country->flag) : null;
        $country->load('forcedOperator');

        return response()->json([
            'status' => 'success',
            'message' => "Le corridor {$country->name} a été mis à jour avec succès.",
            'data' => $country,
        ], 200);
    }

    /**
     * Supprime un pays. Refusé tant qu'il sert : la base supprimerait en cascade ses opérateurs, services et règles
     * de frais (et bloquerait sur les bénéficiaires), et l'historique perdrait son pays. Pour fermer un corridor,
     * on le suspend. Les règles de champs bancaires, propres au pays, partent avec lui.
     */
    public function destroy(string $id)
    {
        $country = Country::findOrFail($id);

        $usage = array_filter([
            'opérateur(s)' => Operator::where('country_id', $country->id)->count(),
            'service(s) configuré(s)' => CountryService::where('country_id', $country->id)->count(),
            'règle(s) de frais' => FeeRule::where('country_id', $country->id)->count(),
            'bénéficiaire(s) bancaire(s)' => BankBeneficiary::where('country_id', $country->id)->count(),
            'transaction(s)' => Transaction::where('destination_country_id', $country->id)->orWhere('country_name', $country->name)->count(),
        ]);

        if ($usage) {
            $detail = collect($usage)->map(fn ($n, $label) => "{$n} {$label}")->implode(', ');

            return response()->json([
                'status' => 'error',
                'error_code' => 'COUNTRY_IN_USE',
                'message' => "« {$country->name} » est encore utilisé ({$detail}) : suspendez le corridor plutôt que de le supprimer.",
            ], 409);
        }

        if ($country->flag) {
            Storage::disk('public')->delete($country->flag);
        }

        $country->delete();

        return response()->json(['status' => 'success', 'message' => "Le corridor {$country->name} a été supprimé."]);
    }

    /**
     * Les codes sont toujours stockés en majuscules : les recherches (Country::resolveActive, API marchande)
     * comparent avec strtoupper(), un « cm » minuscule ne serait jamais retrouvé. Fait AVANT la validation
     * pour que l'unicité se vérifie sur la valeur normalisée.
     */
    private function normalizeCodes(Request $request): void
    {
        foreach (['iso', 'iso3', 'currency'] as $field) {
            if ($request->has($field)) {
                $request->merge([$field => strtoupper(trim((string) $request->input($field)))]);
            }
        }
    }
}
