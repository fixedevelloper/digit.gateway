<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vue d'un transfert pour les agents/admins : client, coordonnées complètes du
 * bénéficiaire, assignation, preuves et historique (quand chargés).
 *
 * @mixin \App\Models\Transaction
 */
class AgentTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $bank = $this->bankBeneficiary;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'customer' => ['id' => $this->user_id, 'name' => $this->user?->name, 'phone' => $this->user?->phone],
            'amount' => (float) $this->amount_sent,
            'fee' => (float) $this->fees,
            'currency' => $this->currency_sent,
            'amount_to_pay' => (float) $this->amount_to_receive,
            'currency_to_pay' => $this->currency_received,
            'destination_country' => $this->destinationCountry?->name ?? $this->country_name,
            'service' => $this->service->value,
            'processing_mode' => $this->processing_mode->value,
            'operator' => $this->recipient_operator,
            'beneficiary' => $bank
                ? $bank->only(['full_name', 'phone', 'email', 'bank_name', 'bank_code', 'branch_code', 'account_number', 'iban', 'swift_bic', 'address', 'city', 'reference', 'pix', 'bre_b', 'spei'])
                : ['full_name' => $this->recipient_name, 'phone' => $this->recipient_phone, 'operator' => $this->recipient_operator],
            'priority' => $this->priority,
            'status' => $this->status,
            'assigned_agent' => $this->whenLoaded('assignedAgent', fn () => $this->assignedAgent ? ['id' => $this->assignedAgent->id, 'name' => $this->assignedAgent->name] : null),
            'assigned_agent_id' => $this->assigned_agent_id,
            'provider_reference' => $this->provider_reference,
            'processed_by' => $this->processed_by,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'failure_reason' => $this->failure_reason,
            'proofs' => $this->whenLoaded('proofs', fn () => $this->proofs->map(fn ($p) => [
                'id' => $p->id, 'file_name' => $p->file_name, 'mime_type' => $p->mime_type, 'size' => $p->size, 'uploaded_by' => $p->uploaded_by, 'created_at' => $p->created_at?->toIso8601String(),
            ])),
            'history' => $this->whenLoaded('auditLogs', fn () => $this->auditLogs->map(fn ($l) => [
                'action' => $l->action, 'user_id' => $l->user_id, 'role' => $l->role, 'old_status' => $l->old_status, 'new_status' => $l->new_status, 'comment' => $l->comment, 'metadata' => $l->metadata, 'created_at' => $l->created_at?->toIso8601String(),
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
