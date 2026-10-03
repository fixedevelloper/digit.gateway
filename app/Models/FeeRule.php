<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeeRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'country_id',
        'service',
        'provider_id',
        'currency',
        'min_amount',
        'max_amount',
        'fixed_fee',
        'percent_fee',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'fixed_fee' => 'decimal:2',
            'percent_fee' => 'decimal:4',
            'active' => 'boolean',
        ];
    }
}
