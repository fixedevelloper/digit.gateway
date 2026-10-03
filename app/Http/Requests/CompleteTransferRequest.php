<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Autorisation par TransferPolicy dans le controller.
    }

    public function rules(): array
    {
        return [
            'provider_reference' => 'nullable|string|max:100',
            'transaction_reference' => 'nullable|string|max:100',
            'comment' => 'nullable|string|max:1000',
        ];
    }
}
