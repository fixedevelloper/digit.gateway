<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantDocument extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = ['user_id', 'type', 'disk', 'path', 'original_name', 'mime', 'size', 'sha256', 'status', 'rejection_reason', 'reviewed_by', 'reviewed_at', 'expires_at'];

    /** Le chemin de stockage n'est jamais exposé : les fichiers passent par un endpoint contrôlé. */
    protected $hidden = ['disk', 'path', 'sha256'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'expires_at' => 'date:Y-m-d'];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
