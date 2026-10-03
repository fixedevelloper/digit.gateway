<?php

namespace App\Services\Banking;

use App\Models\BankBeneficiary;
use App\Models\BankFieldRule;
use App\Models\Country;

/**
 * Champs bancaires obligatoires selon le pays, configurés par l'admin (bank_field_rules).
 * Sans règle pour le pays : config('transfers.bank_default_required').
 */
class BankFieldRequirements
{
    /**
     * @return array<int, string> champs obligatoires parmi BankBeneficiary::FIELDS
     */
    public function requiredFor(Country $country): array
    {
        $rules = BankFieldRule::where('country_id', $country->id)->get();

        $required = $rules->isEmpty()
            ? config('transfers.bank_default_required')
            : $rules->where('required', true)->pluck('field')->all();

        // full_name est toujours exigé : il identifie le bénéficiaire.
        return array_values(array_unique(array_intersect(
            array_merge(['full_name'], $required),
            BankBeneficiary::FIELDS
        )));
    }
}
