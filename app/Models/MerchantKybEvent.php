<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Journal d'audit du dossier marchand : append-only. */
class MerchantKybEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'actor_id', 'action', 'document_type', 'comment'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Le journal du dossier marchand est en lecture seule.'));
        static::deleting(fn () => throw new LogicException('Le journal du dossier marchand est en lecture seule.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
