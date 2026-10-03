<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankBeneficiary extends Model
{
    use HasFactory;

    /** Champs bancaires saisissables ; l'obligation de chacun dépend du pays (BankFieldRequirements). */
    public const FIELDS = [
        'full_name', 'phone', 'email', 'bank_name', 'bank_code', 'branch_code',
        'account_number', 'iban', 'swift_bic', 'address', 'city',
        'reference', 'pix', 'bre_b', 'spei',
    ];

    protected $fillable = ['user_id', 'country_id', ...self::FIELDS];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
