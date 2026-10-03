<?php

namespace App\Models;

use App\Enums\CountryServiceStatus;
use App\Enums\TransferService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CountryService extends Model
{
    use HasFactory;

    protected $fillable = [
        'country_id',
        'service',
        'status',
        'provider_id',
        'min_amount',
        'max_amount',
        'daily_limit',
        'monthly_limit',
    ];

    protected function casts(): array
    {
        return [
            'service' => TransferService::class,
            'status' => CountryServiceStatus::class,
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'daily_limit' => 'decimal:2',
            'monthly_limit' => 'decimal:2',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
