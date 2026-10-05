<?php

namespace App\Models;

use App\Enums\ProcessingMode;
use App\Enums\TransferService;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'recipient_name',
        'bank_beneficiary_id',
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
        'reconciliation_flagged_at',
        'failure_reason',
        'service',
        'processing_mode',
        'provider_id',
        'provider_reference',
        'destination_country_id',
        'assigned_agent_id',
        'processed_by',
        'processed_at',
        'rejection_reason',
        'priority',
        'idempotency_key',
    ];

    /**
     * Statuts du traitement manuel, ajoutés aux statuts historiques (pending, processing,
     * success, failed, reversed). `success` tient lieu de COMPLETED.
     */
    public const STATUS_PENDING_MANUAL_REVIEW = 'pending_manual_review';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Champs internes (agent, routage, idempotence) jamais exposés aux clients par la
     * sérialisation JSON (/transactions, /history...). Les vues agent et admin passent par
     * AgentTransferResource ou `makeVisible(self::INTERNAL_FIELDS)`.
     */
    public const INTERNAL_FIELDS = [
        'assigned_agent_id',
        'processed_by',
        'provider_id',
        'provider_reference',
        'idempotency_key',
        'priority',
    ];

    protected $hidden = self::INTERNAL_FIELDS;

    /**
     * Mêmes valeurs par défaut qu'en base : un modèle fraîchement créé (sans rechargement)
     * se comporte comme une transaction Mobile Money automatique historique.
     */
    protected $attributes = [
        'service' => 'MOBILE_MONEY',
        'processing_mode' => 'AUTOMATIC',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reconciliation_flagged_at' => 'datetime',
            'processed_at' => 'datetime',
            'service' => TransferService::class,
            'processing_mode' => ProcessingMode::class,
        ];
    }

    protected static function booted(): void
    {
        // Tout changement de statut d'une transaction du canal marchand est notifié à ses
        // webhooks, quel que soit le chemin (webhook Digitwave, cron, agent, sandbox, admin).
        static::updated(function (Transaction $transaction) {
            if ($transaction->channel === 'merchant_api' && $transaction->wasChanged('status')) {
                app(WebhookDispatcher::class)->transactionChanged($transaction);
            }
        });
    }

    public function isManual(): bool
    {
        return $this->processing_mode === ProcessingMode::Manual;
    }

    public function bankBeneficiary(): BelongsTo
    {
        return $this->belongsTo(BankBeneficiary::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function destinationCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'destination_country_id');
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(TransferProof::class, 'transfer_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(TransferAuditLog::class, 'transfer_id');
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
            'service' => $this->service->value,
            'processing_mode' => $this->processing_mode->value,
            'status' => $this->status,
            'amount' => (float) $this->amount_sent,
            'fee' => (float) $this->fees,
            'currency' => $this->currency_sent,
            'amount_received' => (float) $this->amount_to_receive,
            'currency_received' => $this->currency_received,
            'exchange_rate' => (float) $this->exchange_rate,
            'recipient' => $this->service === TransferService::BankTransfer
                ? [
                    'name' => $this->recipient_name,
                    'bank_name' => $this->bankBeneficiary?->bank_name,
                    'country' => $this->country_name,
                ]
                : [
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
