<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agency extends Model
{
    use HasFactory;

    /**
     * Les attributs qui peuvent être assignés en masse.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'name',
        'city',
        'address',
        'status',
    ];

    /**
     * Les attributs qui doivent être castés dans des types natifs.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => 'string', // Peut être géré via un Enum PHP si vous utilisez PHP 8.1+
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Scope pour récupérer uniquement les agences actives.
     *
     * Utilisation : Agency::active()->get();
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Relation : Une agence possède plusieurs transactions / retraits.
     *
     * @return HasMany
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'agency_id');
    }
}
