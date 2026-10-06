<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantProfile extends Model
{
    protected $fillable = ['user_id', 'registration_number', 'tax_id', 'country', 'address', 'business_description', 'expected_monthly_volume'];

    protected function casts(): array
    {
        return ['expected_monthly_volume' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
