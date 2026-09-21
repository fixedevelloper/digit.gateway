<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'apikey' => 'nullable|string',
            'country' => 'required|string',
            'agensic_code' => 'required|string',
            'carrier' => 'required|string',
            'number' => 'required|string',
            'amount' => 'required|numeric|min:1',
            // Requis uniquement sur les routes mobile (Sanctum + middleware 'pin.verify').
            // Les routes /v1/gateway/* (clé API) n'en imposent pas : la clé API est le secret.
            'pin' => 'sometimes|digits:4',
        ];
    }
}
