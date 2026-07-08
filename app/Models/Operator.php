<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Operator extends Model
{
    use HasFactory;

    /**
     * Les attributs qui peuvent être assignés en masse.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'country_id',
        'name',
        'code',
        'logo',
        'status',
        'prefix_regex',
        'phone_length',
        'min_amount',
        'max_amount',
    ];

    /**
     * Les attributs qui doivent être convertis (castés) vers des types natifs.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => 'boolean',
        'phone_length' => 'integer',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
    ];

    /**
     * Obtenir le pays auquel appartient cet opérateur.
     * 
     * @return BelongsTo
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * Obtenir toutes les transactions associées à cet opérateur.
     * (Optionnel : si tu adaptes la table transactions pour lier un operator_id)
     * 
     * @return HasMany
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'recipient_operator', 'code');
    }
}