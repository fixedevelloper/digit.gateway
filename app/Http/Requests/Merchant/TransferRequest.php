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
            'country' => 'required_without_all:operator_id,quote_id|string',
            'carrier' => 'required_without_all:operator_id,quote_id|string',
            'number' => 'required|string',
            'amount' => 'required|numeric|min:1',
            // Conversion de devises / opérateurs de même code en plusieurs devises :
            // `operator_id` ou `currency` lève l'ambiguïté, `quote_id` valide une cotation (POST /quote).
            'operator_id' => 'sometimes|nullable|integer',
            'currency' => 'sometimes|nullable|string|size:3',
            'quote_id' => 'sometimes|nullable|uuid',
        ];
    }
}
