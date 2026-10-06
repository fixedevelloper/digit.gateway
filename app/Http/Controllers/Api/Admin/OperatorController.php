<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Operator;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
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
            $operator->logo_url = $operator->logo ? asset('storage/'.$operator->logo) : null;

            return $operator;
        });

        return response()->json($operators, 200);
    }

    /**
     * Enregistre une nouvelle infrastructure réseau (Opérateur).
     *
     * @return JsonResponse
     */
    public function store(Request $request)
    {
        // Un même code (ou nom) peut exister plusieurs fois dans un pays, une fois par
        // devise (ex: VODACOM_CD en CDF et en USD). Par défaut : XAF, devise des wallets
        // (aucune conversion).
        $request->merge(['currency' => strtoupper((string) $request->input('currency', 'XAF'))]);
        $sameCorridor = fn () => Rule::unique('operators')
            ->where('country_id', $request->input('country_id'))
            ->where('currency', $request->input('currency'));

        $validatedData = $request->validate([
            'name' => ['required', 'string', 'max:100', $sameCorridor()],
            'code' => ['required', 'string', 'max:50', $sameCorridor()],
            'currency' => 'required|string|size:3|alpha',
            'country_id' => 'required|exists:countries,id',
            'prefix_regex' => 'nullable|string|max:255',
            'phone_length' => 'required|integer|min:1|max:15',
            'fixed_fee' => 'required|numeric|min:0',
            'percent_fee' => 'required|numeric|min:0|max:1',
            'min_amount' => 'required|numeric|min:0',
            'max_amount' => 'required|numeric|gt:min_amount',
            // Pas de règle 'image' : elle rejette catégoriquement les SVG (protection XSS
            // intégrée à Laravel), en contradiction avec 'mimes' qui les autorise explicitement.
            'logo' => 'nullable|mimes:jpeg,png,jpg,webp,svg|max:2048', // Max 2Mo
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
        $operator->logo_url = $operator->logo ? asset('storage/'.$operator->logo) : null;

        return response()->json([
            'status' => 'success',
            'message' => "L'opérateur {$operator->name} a été configuré et ajouté au corridor avec succès.",
            'data' => $operator,
        ], 201);
    }

    /**
     * Met à jour les configurations techniques, financières et le branding d'un opérateur.
     * Supporte l'envoi multipart/form-data via spoofing de méthode (_method=PUT).
     *
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        $operator = Operator::findOrFail($id);

        if ($blocked = $this->refuseIdentityChangeWhenUsed($request, $operator)) {
            return $blocked;
        }

        if ($request->has('currency')) {
            $request->merge(['currency' => strtoupper((string) $request->input('currency'))]);
        }

        // Changer de pays ou de devise peut créer un doublon de code : on force alors
        // la vérification d'unicité du code actuel dans le nouveau corridor.
        if ($request->hasAny(['country_id', 'currency'])) {
            $request->mergeIfMissing(['code' => $operator->code]);
        }

        // Unicité du nom et du code par pays + devise (excluant l'id courant), évaluée
        // sur les valeurs finales : celles envoyées, sinon celles déjà en base.
        $sameCorridor = fn () => Rule::unique('operators')
            ->where('country_id', $request->input('country_id', $operator->country_id))
            ->where('currency', $request->input('currency', $operator->currency))
            ->ignore($operator->id);

        $validatedData = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', $sameCorridor()],
            'code' => ['sometimes', 'string', 'max:50', $sameCorridor()],
            'currency' => 'sometimes|string|size:3|alpha',
            'country_id' => 'sometimes|exists:countries,id',
            'status' => 'sometimes|boolean',
            'prefix_regex' => 'sometimes|nullable|string|max:255',
            'phone_length' => 'sometimes|integer|min:1|max:15',
            'fixed_fee' => 'sometimes|numeric|min:0',
            'percent_fee' => 'sometimes|numeric|min:0|max:1',
            'min_amount' => 'sometimes|numeric|min:0',
            'max_amount' => 'sometimes|numeric|gt:min_amount',
            'logo' => 'sometimes|nullable|mimes:jpeg,png,jpg,webp,svg|max:2048',
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
        $operator->logo_url = $operator->logo ? asset('storage/'.$operator->logo) : null;

        return response()->json([
            'status' => 'success',
            'message' => "Configuration de la passerelle {$operator->name} mise à jour avec succès.",
            'data' => $operator,
        ], 200);
    }

    /**
     * Supprime un opérateur. Refusé tant que son historique de transactions existe, ou qu'il est l'opérateur
     * forcé d'un pays : les transactions perdraient leur lien (operator_id → null) et le routage d'urgence
     * d'un pays pointerait dans le vide. Pour le couper, on le désactive (« Coupé »). Ses cotations en attente
     * (éphémères) disparaissent avec lui.
     */
    public function destroy(string $id)
    {
        $operator = Operator::with('country')->findOrFail($id);

        $usage = array_filter([
            'transaction(s)' => $this->transactionsOf($operator)->count(),
            'pays où il est forcé' => Country::where('forced_operator_id', $operator->id)->count(),
        ]);

        if ($usage) {
            $detail = collect($usage)->map(fn ($n, $label) => "{$n} {$label}")->implode(', ');

            return response()->json([
                'status' => 'error',
                'error_code' => 'OPERATOR_IN_USE',
                'message' => "« {$operator->name} » est encore utilisé ({$detail}) : coupez-le (désactivez-le) plutôt que de le supprimer.",
            ], 409);
        }

        if ($operator->logo && ! filter_var($operator->logo, FILTER_VALIDATE_URL)) {
            Storage::disk('public')->delete($operator->logo);
        }

        $operator->delete();

        return response()->json(['status' => 'success', 'message' => "L'opérateur {$operator->name} a été supprimé."]);
    }

    /** Transactions rattachées à l'opérateur (par id, ou par code + pays pour les plus anciennes). */
    private function transactionsOf(Operator $operator)
    {
        return Transaction::where('operator_id', $operator->id)
            ->orWhere(fn ($q) => $q->where('recipient_operator', $operator->code)->where('country_name', $operator->country?->name));
    }

    /**
     * Code, pays et devise identifient l'opérateur dans l'historique (`recipient_operator`) et les corridors :
     * on ne les modifie plus dès qu'il a servi. Le nom, les frais, les bornes, le logo et le statut restent libres.
     */
    private function refuseIdentityChangeWhenUsed(Request $request, Operator $operator)
    {
        $changes = array_filter([
            'code' => $request->has('code') && (string) $request->input('code') !== $operator->code,
            'country_id' => $request->has('country_id') && (int) $request->input('country_id') !== (int) $operator->country_id,
            'currency' => $request->has('currency') && strtoupper((string) $request->input('currency')) !== $operator->currency,
        ]);

        if (! $changes) {
            return null;
        }

        $operator->loadMissing('country');

        if ($this->transactionsOf($operator)->doesntExist()) {
            return null;
        }

        return response()->json([
            'message' => 'Cet opérateur a déjà des transactions : son code, son pays et sa devise ne peuvent plus être modifiés (créez un nouvel opérateur, et coupez celui-ci).',
            'errors' => collect($changes)->map(fn () => ['Modification impossible : opérateur déjà utilisé.'])->all(),
        ], 422);
    }
}
