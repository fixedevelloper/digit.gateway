<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankBeneficiary;
use App\Models\BankFieldRule;
use App\Models\Country;
use App\Services\Banking\BankFieldRequirements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Champs bancaires obligatoires d'un pays (ex: IBAN + BIC en Europe, account_number +
 * bank_code au Cameroun). Sans règle, les défauts de config/transfers.php s'appliquent.
 */
class BankFieldRuleController extends Controller
{
    public function show(string $id, BankFieldRequirements $requirements): JsonResponse
    {
        Gate::authorize('manage', Country::class);

        return response()->json(['required_fields' => $requirements->requiredFor(Country::findOrFail($id))]);
    }

    /**
     * Remplace l'ensemble des règles du pays : `fields` = { champ: obligatoire(bool) }.
     * Un corps `fields` vide rétablit les défauts.
     */
    public function update(Request $request, string $id, BankFieldRequirements $requirements): JsonResponse
    {
        Gate::authorize('manage', Country::class);

        $country = Country::findOrFail($id);
        $data = $request->validate(['fields' => 'present|array', 'fields.*' => 'boolean']);

        $unknown = array_diff(array_keys($data['fields']), BankBeneficiary::FIELDS);
        if ($unknown) {
            throw ValidationException::withMessages(['fields' => ['Champs inconnus : '.implode(', ', $unknown)]]);
        }

        DB::transaction(function () use ($country, $data) {
            BankFieldRule::where('country_id', $country->id)->delete();

            foreach ($data['fields'] as $field => $required) {
                BankFieldRule::create(['country_id' => $country->id, 'field' => $field, 'required' => (bool) $required]);
            }
        });

        return response()->json(['status' => 'success', 'required_fields' => $requirements->requiredFor($country)]);
    }
}
