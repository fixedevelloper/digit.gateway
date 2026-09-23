<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Taux de change saisi manuellement par l'admin (voir Admin\ExchangeRateController).
 * `rate` = unités de `quote_currency` pour 1 unité de `base_currency`
 * (ex: base USD, quote XAF, rate 605 ⇒ 1 USD = 605 XAF). Table en ajout seul :
 * la ligne la plus récente d'une paire fait foi, les autres forment l'historique.
 */
class ExchangeRate extends Model
{
    protected $fillable = [
        'base_currency',
        'quote_currency',
        'rate',
        'created_by',
    ];

    protected $casts = [
        'rate' => 'float',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
