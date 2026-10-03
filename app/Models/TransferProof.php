<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Métadonnées d'une preuve de transfert (le fichier est sur un disque privé). Une preuve
 * déposée n'est ni modifiable ni supprimable.
 */
class TransferProof extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['transfer_id', 'uploaded_by', 'disk', 'file_path', 'file_name', 'mime_type', 'size'];

    protected $hidden = ['disk', 'file_path'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une preuve de transfert est immuable.'));
        static::deleting(fn () => throw new LogicException('Une preuve de transfert est immuable.'));
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transfer_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
