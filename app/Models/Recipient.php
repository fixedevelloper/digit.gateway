<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipient extends Model
{
    use HasFactory;

    /**
     * Les attributs qui peuvent être assignés en masse.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'operator',
        'country_code',
    ];

    /**
     * Obtenir l'utilisateur (le compte client) à qui appartient ce bénéficiaire.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Obtenir toutes les transactions envoyées à ce bénéficiaire.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
