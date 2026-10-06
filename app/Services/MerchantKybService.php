<?php

namespace App\Services;

use App\Models\MerchantDocument;
use App\Models\MerchantKybEvent;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Notifications\KybReviewedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Dossier de vérification d'un marchand (KYB) : informations d'entreprise + pièces justificatives.
 *
 * Statuts du dossier (users.kyb_status) :
 *   incomplete → le marchand dépose ses pièces ; passe à in_review quand il soumet ;
 *   in_review  → verrouillé pour le marchand, l'équipe examine ;
 *   approved   → toutes les pièces obligatoires validées : le passage en production est possible ;
 *   rejected   → refus global motivé : le marchand corrige puis soumet à nouveau.
 * Le refus d'une pièce renvoie le dossier en `incomplete`.
 */
class MerchantKybService
{
    public const INCOMPLETE = 'incomplete';

    public const IN_REVIEW = 'in_review';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** @return array<string, array{label: string, required: bool, expires?: bool}> */
    public function documentTypes(): array
    {
        return config('kyb.documents');
    }

    /** @return array<int, string> */
    public function requiredTypes(): array
    {
        return array_keys(array_filter($this->documentTypes(), fn ($d) => $d['required']));
    }

    public function profileComplete(User $merchant): bool
    {
        $profile = $merchant->merchantProfile;

        if (! $profile) {
            return false;
        }

        foreach (config('kyb.profile_required') as $field) {
            if ($profile->{$field} === null || $profile->{$field} === '') {
                return false;
            }
        }

        return true;
    }

    /** Pièces obligatoires manquantes, refusées ou expirées. @return array<int, string> */
    public function missingOrInvalid(User $merchant, bool $requireApproved = false): array
    {
        $docs = $merchant->merchantDocuments()->get()->keyBy('type');

        return array_values(array_filter($this->requiredTypes(), function (string $type) use ($docs, $requireApproved) {
            $doc = $docs->get($type);

            if (! $doc || $doc->status === MerchantDocument::REJECTED || $doc->isExpired()) {
                return true;
            }

            return $requireApproved && $doc->status !== MerchantDocument::APPROVED;
        }));
    }

    public function readyToSubmit(User $merchant): bool
    {
        return $this->profileComplete($merchant) && $this->missingOrInvalid($merchant) === [];
    }

    /** Vue complète pour le portail marchand et la console admin. */
    public function overview(User $merchant): array
    {
        $docs = $merchant->merchantDocuments()->get()->keyBy('type');

        return [
            'kyb_status' => $merchant->kyb_status,
            'rejection_reason' => $merchant->kyb_rejection_reason,
            'grace_until' => $merchant->kyb_grace_until?->toIso8601String(),
            'profile' => $merchant->merchantProfile,
            'profile_complete' => $this->profileComplete($merchant),
            'can_submit' => $this->readyToSubmit($merchant) && in_array($merchant->kyb_status, [self::INCOMPLETE, self::REJECTED], true),
            'documents' => collect($this->documentTypes())->map(fn ($def, $type) => [
                'type' => $type,
                'label' => $def['label'],
                'required' => $def['required'],
                'expires' => $def['expires'] ?? false,
                'document' => $docs->get($type),
                'expired' => (bool) $docs->get($type)?->isExpired(),
            ])->values()->all(),
        ];
    }

    public function saveProfile(User $merchant, array $data): MerchantProfile
    {
        $this->assertEditable($merchant);

        $profile = MerchantProfile::updateOrCreate(['user_id' => $merchant->id], $data);
        $this->log($merchant, $merchant, 'profile_updated');

        return $profile;
    }

    /**
     * Dépose (ou remplace) la pièce d'un type. Un remplacement repasse la pièce en attente ; sur un dossier déjà
     * approuvé, il le renvoie en examen (le passage en production déjà accordé n'est pas retiré).
     *
     * @throws InvalidArgumentException
     */
    public function upload(User $merchant, string $type, UploadedFile $file, ?string $expiresAt = null): MerchantDocument
    {
        if (! array_key_exists($type, $this->documentTypes())) {
            throw new InvalidArgumentException('Type de pièce inconnu.');
        }

        $this->assertEditable($merchant);

        $disk = config('kyb.disk');
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $path = Storage::disk($disk)->putFileAs("kyb/{$merchant->id}", $file, Str::uuid().'.'.$extension);

        $replaced = false;

        $document = DB::transaction(function () use ($merchant, $type, $file, $expiresAt, $disk, $path, &$replaced) {
            $previous = MerchantDocument::where('user_id', $merchant->id)->where('type', $type)->lockForUpdate()->first();

            if ($previous) {
                $replaced = true;
                Storage::disk($previous->disk)->delete($previous->path);
            }

            $document = MerchantDocument::updateOrCreate(['user_id' => $merchant->id, 'type' => $type], [
                'disk' => $disk,
                'path' => $path,
                'original_name' => Str::limit(basename($file->getClientOriginalName()), 200, ''),
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'status' => MerchantDocument::PENDING,
                'rejection_reason' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'expires_at' => $expiresAt,
            ]);

            if ($merchant->kyb_status === self::APPROVED) {
                $this->setStatus($merchant, self::IN_REVIEW);
            }

            return $document;
        });

        $this->log($merchant, $merchant, $replaced ? 'document_replaced' : 'document_uploaded', $type);

        return $document;
    }

    /** @throws InvalidArgumentException */
    public function submit(User $merchant): void
    {
        $this->assertEditable($merchant);

        if (! $this->readyToSubmit($merchant)) {
            throw new InvalidArgumentException('Le dossier est incomplet : renseignez les informations d\'entreprise et déposez toutes les pièces obligatoires valides.');
        }

        $this->setStatus($merchant, self::IN_REVIEW, null);
        $this->log($merchant, $merchant, 'submitted');
    }

    /** @throws InvalidArgumentException */
    public function reviewDocument(MerchantDocument $document, User $admin, bool $approve, ?string $reason = null): MerchantDocument
    {
        $merchant = $document->user;

        if ($merchant->kyb_status !== self::IN_REVIEW) {
            throw new InvalidArgumentException('Le dossier n\'est pas en cours d\'examen.');
        }

        $document->update([
            'status' => $approve ? MerchantDocument::APPROVED : MerchantDocument::REJECTED,
            'rejection_reason' => $approve ? null : $reason,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        $this->log($merchant, $admin, $approve ? 'document_approved' : 'document_rejected', $document->type, $reason);

        // Une pièce refusée renvoie le dossier au marchand pour correction.
        if (! $approve) {
            $this->setStatus($merchant, self::INCOMPLETE, null);
            $merchant->notify(new KybReviewedNotification('document_rejected', $this->documentTypes()[$document->type]['label'], $reason));
        }

        return $document->fresh();
    }

    /** @throws InvalidArgumentException */
    public function approveDossier(User $merchant, User $admin): void
    {
        if ($merchant->kyb_status !== self::IN_REVIEW) {
            throw new InvalidArgumentException('Le dossier n\'est pas en cours d\'examen.');
        }

        if (! $this->profileComplete($merchant) || $this->missingOrInvalid($merchant, requireApproved: true) !== []) {
            throw new InvalidArgumentException('Toutes les pièces obligatoires doivent être approuvées et non expirées.');
        }

        $this->setStatus($merchant, self::APPROVED, null);
        $merchant->forceFill(['kyb_reviewed_by' => $admin->id, 'kyb_reviewed_at' => now(), 'kyb_grace_until' => null])->save();
        $this->log($merchant, $admin, 'dossier_approved');
        $merchant->notify(new KybReviewedNotification('approved'));
    }

    /** @throws InvalidArgumentException */
    public function rejectDossier(User $merchant, User $admin, string $reason): void
    {
        if ($merchant->kyb_status !== self::IN_REVIEW) {
            throw new InvalidArgumentException('Le dossier n\'est pas en cours d\'examen.');
        }

        $this->setStatus($merchant, self::REJECTED, $reason);
        $merchant->forceFill(['kyb_reviewed_by' => $admin->id, 'kyb_reviewed_at' => now()])->save();
        $this->log($merchant, $admin, 'dossier_rejected', null, $reason);
        $merchant->notify(new KybReviewedNotification('rejected', null, $reason));
    }

    /** Trace chaque consultation d'une pièce par l'équipe. */
    public function logView(MerchantDocument $document, User $admin): void
    {
        $this->log($document->user, $admin, 'document_viewed', $document->type);
    }

    private function assertEditable(User $merchant): void
    {
        if ($merchant->kyb_status === self::IN_REVIEW) {
            throw new InvalidArgumentException('Le dossier est en cours d\'examen : il ne peut pas être modifié pour l\'instant.');
        }
    }

    private function setStatus(User $merchant, string $status, ?string $reason = null): void
    {
        $merchant->forceFill(['kyb_status' => $status, 'kyb_rejection_reason' => $reason])->save();
    }

    private function log(User $merchant, ?User $actor, string $action, ?string $type = null, ?string $comment = null): void
    {
        MerchantKybEvent::create([
            'user_id' => $merchant->id,
            'actor_id' => $actor?->id,
            'action' => $action,
            'document_type' => $type,
            'comment' => $comment ? Str::limit($comment, 500, '') : null,
        ]);
    }
}
