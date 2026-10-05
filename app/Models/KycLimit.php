<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KycLimit extends Model
{
    protected $primaryKey = 'level';

    public $incrementing = false;

    protected $fillable = ['level', 'per_transaction', 'daily_limit', 'monthly_limit'];

    protected function casts(): array
    {
        return [
            'per_transaction' => 'decimal:2',
            'daily_limit' => 'decimal:2',
            'monthly_limit' => 'decimal:2',
        ];
    }
}
