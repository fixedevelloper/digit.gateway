<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Operator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class OperatorController extends Controller
{
    /**
     * Liste tous les opérateurs avec les détails de leur pays.
     * Consommé par le hook 'useOperators' du dashboard Next.js.
     */
    public function index()
    {
        // Chargement de la relation 'country'
        $operators = Operator::with('country')->orderBy('name', 'asc')->get();

        // Injecte dynamiquement l'URL absolue du logo pour Next.js
        $operators->transform(function ($operator) {
            $operator->logo_url = $operator->logo ? asset('storage/' . $operator->logo) : null;
            return $operator;
        });

        return response()->json($operators, 200);
    }

    /**
     * Enregistre une nouvelle infrastructure réseau (Opérateur).
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name'         => 'required|string|max:100|unique:operators,name',
            'code'         => 'required|string|max:50|unique:operators,code',
            'country_id'   => 'required|exists:countries,id',
            'prefix_regex' => 'nullable|string|max:255',
            'phone_length' => 'required|integer|min:1|max:15',
            'fixed_fee'    => 'required|numeric|min:0',
            'percent_fee'  => 'required|numeric|min:0|max:1',
            'min_amount'   => 'required|numeric|min:0',
            'max_amount'   => 'required|numeric|gt:min_amount',
            'logo'         => 'nullable|image|mimes:jpeg,png,jpg,webp,svg|max:2048', // Max 2Mo
        ]);

        // Gestion de l'upload du logo
        if ($request->hasFile('logo')) {
            // Stocké dans storage/app/public/logos
            $path = $request->file('logo')->store('logos', 'public');
            $validatedData['logo'] = $path;
        }

        // Par défaut, l'opérateur est actif à sa création
        $validatedData['status'] = true;

        $operator = Operator::create($validatedData);

        // Rechargement du pays associé et injection de l'URL absolue du logo
        $operator->load('country');
        $operator->logo_url = $operator->logo ? asset('storage/' . $operator->logo) : null;

        return response()->json([
            'status'  => 'success',
            'message' => "L'opérateur {$operator->name} a été configuré et ajouté au corridor avec succès.",
            'data'    => $operator
        ], 201);
    }

    /**
     * Met à jour les configurations techniques, financières et le branding d'un opérateur.
     * Supporte l'envoi multipart/form-data via spoofing de méthode (_method=PUT).
     * @param Request $request
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $operator = Operator::findOrFail($id);

        logger($request->all());
        // Validation stricte incluant l'unicité du nom et du code (excluant l'id courant)
        $validatedData = $request->validate([
            'name'         => ['sometimes', 'string', 'max:100', Rule::unique('operators')->ignore($operator->id)],
            'code'         => ['sometimes', 'string', 'max:50', Rule::unique('operators')->ignore($operator->id)],
            'country_id'   => 'sometimes|exists:countries,id',
            'status'       => 'sometimes|boolean',
            'prefix_regex' => 'sometimes|nullable|string|max:255',
            'phone_length' => 'sometimes|integer|min:1|max:15',
            'fixed_fee'    => 'sometimes|numeric|min:0',
            'percent_fee'  => 'sometimes|numeric|min:0|max:1',
            'min_amount'   => 'sometimes|numeric|min:0',
            'max_amount'   => 'sometimes|numeric|gt:min_amount',
            'logo'         => 'sometimes|nullable|image|mimes:jpeg,png,jpg,webp,svg|max:2048',
        ]);

        // Gestion de l'upload du nouveau logo
        if ($request->hasFile('logo')) {
            // Nettoyage : On supprime l'ancien logo s'il existe
            if ($operator->logo) {
                Storage::disk('public')->delete($operator->logo);
            }

            // Enregistrement du nouveau logo
            $path = $request->file('logo')->store('logos', 'public');
            $validatedData['logo'] = $path;
        }

        // Application des modifications
        $operator->update($validatedData);

        // On recharge la relation et on injecte le lien absolu
        $operator->load('country');
        $operator->logo_url = $operator->logo ? asset('storage/' . $operator->logo) : null;

        return response()->json([
            'status'  => 'success',
            'message' => "Configuration de la passerelle {$operator->name} mise à jour avec succès.",
            'data'    => $operator
        ], 200);
    }
}
