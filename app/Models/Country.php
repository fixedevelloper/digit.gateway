<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
     * Accesseur (Accessor) pour le drapeau.
     * Si aucun drapeau n'est défini en base de données, cela génère dynamiquement 
     * un lien vers un CDN de drapeaux haute qualité basé sur le code ISO (ex: CM, CI).
     * Très propre pour un rendu instantané dans votre application Flutter !
     */
    public function getFlagAttribute($value)
    {
        if ($value) {
            return $value;
        }

        // Utilisation d'un service public stable pour générer les drapeaux en SVG flat design
        return 'https://purecatamphetamine.github.io/country-flag-icons/3x2/' . strtoupper($this->iso) . '.svg';
    }

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
 *
 * @return \Illuminate\Database\Eloquent\Relations\HasMany
 */
public function operators(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(Operator::class)->where('status', true);
}
}