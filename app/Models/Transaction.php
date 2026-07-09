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
        'user_id',
        'recipient_id',
        'recipient_phone',
        'recipient_operator',
        'amount_sent',
        'currency_sent',
        'fees',
        'exchange_rate',
        'amount_to_receive',
        'currency_received',
        'country_name',
        'status',
        'gateway_reference',
        'failure_reason',
    ];

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
     * Obtenir l'agence associée à cette transaction.
     */
    public function agency()
    {
        return $this->belongsTo(Agency::class, 'agency_id');
    }
}
