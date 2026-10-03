<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransferProof;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stockage des preuves de transfert sur un disque privé (config transfers.proof_disk).
 * Le nom de fichier stocké est généré (jamais celui du client) ; seules les métadonnées
 * vont en base.
 */
class ProofStorageService
{
    public function store(Transaction $transfer, UploadedFile $file, User $uploader): TransferProof
    {
        $disk = config('transfers.proof_disk');
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $path = Storage::disk($disk)->putFileAs(
            "transfer-proofs/{$transfer->id}",
            $file,
            Str::uuid().'.'.$extension
        );

        return TransferProof::create([
            'transfer_id' => $transfer->id,
            'uploaded_by' => $uploader->id,
            'disk' => $disk,
            'file_path' => $path,
            'file_name' => Str::limit(basename($file->getClientOriginalName()), 200, ''),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize(),
        ]);
    }
}
