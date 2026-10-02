<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

    /**
     * Les attributs qui peuvent être assignés en masse.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'reference',
        'type',
        'channel',
        'environment',
        'user_id',
        'agency_id',
        'recipient_id',
        'recipient_phone',
        'recipient_operator',
        'operator_id',
        'quote_id',
        'amount_sent',
        'currency_sent',
        'fees',
        'exchange_rate',
        'amount_to_receive',
        'currency_received',
        'country_name',
        'status',
        'gateway_reference',
        'submitted_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * Représentation publique exposée aux marchands (API gateway et portail).
     *
     * @param  array<string, mixed>  $extra  champs ajoutés à la réponse (ex: solde restant)
     * @return array<string, mixed>
     */
    public function toMerchantArray(array $extra = []): array
    {
        return array_merge([
            'reference' => $this->reference,
            'environment' => $this->environment,
            'type' => $this->type,
            'status' => $this->status,
            'amount' => (float) $this->amount_sent,
            'fee' => (float) $this->fees,
            'currency' => $this->currency_sent,
            'amount_received' => (float) $this->amount_to_receive,
            'currency_received' => $this->currency_received,
            'exchange_rate' => (float) $this->exchange_rate,
            'recipient' => [
                'phone' => $this->recipient_phone,
                'operator' => $this->recipient_operator,
                'country' => $this->country_name,
            ],
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ], $extra);
    }

    /**
     * Obtenir l'utilisateur (l'expéditeur) qui a initié la transaction.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Obtenir le bénéficiaire enregistré associé à la transaction (si applicable).
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    /**
     * Opérateur réellement résolu à la création (distingue deux opérateurs de même
     * code dans un pays, par devise). Null pour les transactions antérieures.
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /**
     * Obtenir l'agence associée à cette transaction.
     */
    public function agency()
    {
        return $this->belongsTo(Agency::class, 'agency_id');
    }
}
