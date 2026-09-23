<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Entrée de POST /quote (app mobile) et POST /v1/gateway/quotes (marchands).
 * `amount` est toujours exprimé dans la devise du wallet (ex: XAF).
 */
class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => 'sometimes|in:transfer,withdrawal,deposit',
            'operator_id' => 'sometimes|nullable|integer',
            'country' => 'required_without:operator_id|string',
            'carrier' => 'required_without:operator_id|string',
            'currency' => 'sometimes|nullable|string|size:3',
            'amount' => 'required|numeric|min:1',
        ];
    }
}
