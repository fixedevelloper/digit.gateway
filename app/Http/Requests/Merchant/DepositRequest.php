<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contrat d'entrée dédié à POST /v1/gateway/deposits (intégration B2B).
 */
class DepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'country' => 'required|string',
            'carrier' => 'required|string',
            'number' => 'required|string',
            'amount' => 'required|numeric|min:1',
        ];
    }
}
