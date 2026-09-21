<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contrat d'entrée dédié à POST /v1/gateway/transfers (intégration B2B). Ne
 * reprend pas les champs `apikey`/`pin` de App\Http\Requests\TransferRequest,
 * hérités de l'app mobile : côté serveur-à-serveur, la clé API et l'en-tête
 * Idempotency-Key jouent déjà ce rôle.
 */
class TransferRequest extends FormRequest
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
