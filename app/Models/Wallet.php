<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wallet extends Model
{
    use HasFactory;

    /**
     * Les attributs qui peuvent être assignés en masse.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'balance',
        'currency',
    ];

    /**
     * Cast automatique des attributs.
     * Crucial pour s'assurer que le solde est traité comme un nombre à virgule (float) en PHP.
     */
    protected $casts = [
        'balance' => 'float',
    ];

    /**
     * Obtenir l'utilisateur propriétaire de ce portefeuille.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
