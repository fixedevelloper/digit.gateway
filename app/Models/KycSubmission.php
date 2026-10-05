<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycSubmission extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'user_id', 'target_level', 'document_type', 'document_number', 'full_name', 'birth_date',
        'files', 'status', 'rejection_reason', 'reviewed_by', 'reviewed_at',
    ];

    /** Les chemins de stockage ne sont jamais exposés : les fichiers passent par un endpoint admin. */
    protected $hidden = ['files'];

    protected function casts(): array
    {
        return ['files' => 'array', 'birth_date' => 'date:Y-m-d', 'reviewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
