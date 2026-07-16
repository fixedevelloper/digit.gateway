<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute; // Requis pour l'écriture moderne

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
     * Les attributs à ajouter automatiquement lors de la conversion en tableau ou JSON.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'logo_url', // Génère automatiquement la clé "logo_url" dans tes payloads JSON
    ];

    /**
     * Obtenir l'URL absolue du logo via la fonction asset().
     * Accessible en PHP via $operator->logo_url
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
        // Si pas de logo renseigné en base, on retourne null
        if (!$this->logo) {
            return null;
        }

        // Si le logo stocké est déjà une URL complète (ex: https://...)
        if (filter_var($this->logo, FILTER_VALIDATE_URL)) {
            return $this->logo;
        }

        // Génère l'URL absolue basée sur le domaine actuel : http://ton-api.com/storage/chemin/du/logo.png
        return asset('storage/' . $this->logo);
    }
        );
    }

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
     *
     * @return HasMany
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'recipient_operator', 'code');
    }
}
