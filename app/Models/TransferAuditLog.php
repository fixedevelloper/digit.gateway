<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Journal d'audit des transferts : append-only. Toute modification ou suppression
 * via Eloquent est refusée (un agent ne peut pas réécrire l'historique).
 */
class TransferAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'transfer_id',
        'user_id',
        'role',
        'action',
        'old_status',
        'new_status',
        'comment',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Le journal d\'audit est en lecture seule.'));
        static::deleting(fn () => throw new LogicException('Le journal d\'audit est en lecture seule.'));
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transfer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
