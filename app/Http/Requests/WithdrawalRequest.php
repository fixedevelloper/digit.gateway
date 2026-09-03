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
            'pin' => 'required|digits:4',
        ];
    }
}
