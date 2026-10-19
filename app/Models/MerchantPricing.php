<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frais et taux de change d'un marchand pour un opérateur (voir TransactionService::price).
 * Sans ligne active, un marchand ne peut pas opérer sur cet opérateur (PRICING_NOT_CONFIGURED).
 */
class MerchantPricing extends Model
{
    protected $table = 'merchant_pricing';

    protected $fillable = [
        'merchant_id',
        'operator_id',
        'fixed_fee',
        'percent_fee',
        'rate',
        'active',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'fixed_fee' => 'float',
            'percent_fee' => 'float',
            'rate' => 'float',
            'active' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }
}
