<?php

namespace App\Http\Requests;

use App\Models\BankBeneficiary;
use App\Models\Country;
use App\Services\Banking\BankFieldRequirements;
use Illuminate\Foundation\Http\FormRequest;

class BankTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Les champs bancaires obligatoires dépendent du pays de destination (configuration admin).
     */
    public function rules(): array
    {
        $country = is_string($this->input('country')) ? Country::resolveActive($this->input('country')) : null;
        $required = $country ? app(BankFieldRequirements::class)->requiredFor($country) : ['full_name'];

        $rules = [
            'country' => 'required|string',
            'amount' => 'required|numeric|min:1',
            'pin' => 'sometimes|digits:4',
            'beneficiary' => 'required|array',
        ];

        $formats = [
            'email' => 'email|max:255',
            'iban' => 'string|max:34',
            'swift_bic' => 'string|max:11',
            'phone' => 'string|max:30',
            'bank_code' => 'string|max:30',
            'branch_code' => 'string|max:30',
            'account_number' => 'string|max:50',
            'reference' => 'string|max:100',
            'pix' => 'string|max:100',
            'bre_b' => 'string|max:100',
            'spei' => 'string|max:18',
        ];

        foreach (BankBeneficiary::FIELDS as $field) {
            $rules["beneficiary.$field"] = (in_array($field, $required, true) ? 'required|' : 'nullable|').($formats[$field] ?? 'string|max:255');
        }

        return $rules;
    }
}
