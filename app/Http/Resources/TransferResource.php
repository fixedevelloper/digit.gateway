<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vue d'un transfert pour son propriétaire (identité de l'agent et audit non exposés).
 *
 * @mixin \App\Models\Transaction
 */
class TransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type,
            'service' => $this->service->value,
            'processing_mode' => $this->processing_mode->value,
            'status' => $this->status,
            'amount' => (float) $this->amount_sent,
            'fee' => (float) $this->fees,
            'total' => (float) $this->amount_sent + (float) $this->fees,
            'currency' => $this->currency_sent,
            'amount_received' => (float) $this->amount_to_receive,
            'currency_received' => $this->currency_received,
            'exchange_rate' => (float) $this->exchange_rate,
            'destination_country' => $this->country_name,
            'beneficiary' => [
                'name' => $this->recipient_name,
                'phone' => $this->recipient_phone ?: null,
                'operator' => $this->recipient_operator,
                'bank_name' => $this->bankBeneficiary?->bank_name,
            ],
            'rejection_reason' => $this->rejection_reason,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
