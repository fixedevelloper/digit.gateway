<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contrat d'entrée dédié à POST /v1/gateway/withdrawals (intégration B2B).
 */
class WithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'country' => 'required|string',
            'agensic_code' => 'required|string',
            'carrier' => 'required|string',
            'number' => 'required|string',
            'amount' => 'required|numeric|min:1',
        ];
    }
}
