<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    use HasFactory;

    /**
     * Les attributs qui sont assignables en masse.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'iso',
        'iso3',
        'currency',
        'numcode',
        'phonecode',
        'status',
        'flag',
        'forced_operator_id',
    ];

    /**
     * Les attributs qui doivent être convertis dans des types natifs.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => 'boolean',
        'numcode' => 'integer',
        'phonecode' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Portée locale (Scope) pour filtrer uniquement les pays actifs.
     * Permet de faire Country::active()->get() directement dans vos contrôleurs.
     */
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    /**
     * Obtenir les opérateurs disponibles pour ce pays.
     */
    public function operators(): HasMany
    {
        return $this->hasMany(Operator::class)->where('status', true);
    }

    /**
     * Opérateur forcé manuellement par l'admin pour ce pays (bascule d'urgence).
     * Quand renseigné, il prend le pas sur l'opérateur choisi par l'expéditeur.
     */
    public function forcedOperator(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'forced_operator_id');
    }
}
