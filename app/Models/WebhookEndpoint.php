<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * URL de rappel d'un marchand. Le secret de signature est stocké chiffré et n'est
 * révélé qu'à la création (comme une clé API).
 */
class WebhookEndpoint extends Model
{
    public const MAX_PER_MERCHANT = 5;

    protected $fillable = ['user_id', 'url', 'secret', 'environment', 'active'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'active' => 'boolean'];
    }

    public static function generateSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
