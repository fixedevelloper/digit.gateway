<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cotation figée présentée au client avant validation d'une opération
 * (voir TransactionService::createQuote). `amount`/`fee`/`total` sont dans la
 * devise du wallet (`currency`), `converted_*` dans celle de l'opérateur.
 */
class Quote extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'operator_id',
        'type',
        'amount',
        'fee',
        'total',
        'currency',
        'converted_amount',
        'converted_fee',
        'converted_currency',
        'rate',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'fee' => 'float',
        'total' => 'float',
        'converted_amount' => 'float',
        'converted_fee' => 'float',
        'rate' => 'float',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }
}
