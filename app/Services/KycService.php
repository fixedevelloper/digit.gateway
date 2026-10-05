<?php

namespace App\Services;

use App\Exceptions\TransactionValidationException;
use App\Models\KycLimit;
use App\Models\KycSubmission;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * KYC par niveaux et plafonds associés.
 *
 * Les plafonds ne s'appliquent qu'aux clients (role = customer) sur l'argent réel qui
 * sort : transferts mobile money, virements bancaires et retraits. Dépôts, sandbox et
 * comptes marchands (validés par l'admin via leur passage en production) sont exclus.
 * Un plafond NULL est illimité.
 */
class KycService
{
    /** Statuts qui ne consomment pas le plafond. */
    private const NON_COUNTED = ['failed', 'reversed', Transaction::STATUS_REJECTED, Transaction::STATUS_CANCELLED];

    public function appliesTo(User $user, string $environment): bool
    {
        return $user->role === 'customer' && $environment === 'production';
    }

    /**
     * À appeler sous le verrou du wallet de l'utilisateur (ce qui sérialise ses requêtes
     * et évite qu'une rafale dépasse le plafond).
     *
     * @throws TransactionValidationException
     */
    public function assertWithinLimits(User $user, float $amount, string $environment = 'production'): void
    {
        if (! $this->appliesTo($user, $environment)) {
            return;
        }

        $limit = KycLimit::find($user->kyc_level);

        if (! $limit) {
            return;
        }

        $hint = $user->kyc_level < max(config('kyc.levels'))
            ? ' Vérifiez votre identité (KYC) pour relever vos plafonds.'
            : '';

        if ($limit->per_transaction !== null && $amount > (float) $limit->per_transaction) {
            throw TransactionValidationException::make('KYC_LIMIT_PER_TRANSACTION', 'amount', 'Montant supérieur au plafond par transaction de votre niveau.'.$hint);
        }

        if ($limit->daily_limit !== null && $this->used($user, now()->startOfDay()) + $amount > (float) $limit->daily_limit) {
            throw TransactionValidationException::make('KYC_DAILY_LIMIT_EXCEEDED', 'amount', 'Plafond journalier de votre niveau dépassé.'.$hint);
        }

        if ($limit->monthly_limit !== null && $this->used($user, now()->startOfMonth()) + $amount > (float) $limit->monthly_limit) {
            throw TransactionValidationException::make('KYC_MONTHLY_LIMIT_EXCEEDED', 'amount', 'Plafond mensuel de votre niveau dépassé.'.$hint);
        }
    }

    public function used(User $user, $since): float
    {
        return (float) Transaction::where('user_id', $user->id)
            ->where('environment', 'production')
            ->whereIn('type', ['transfer', 'withdrawal'])
            ->whereNotIn('status', self::NON_COUNTED)
            ->where('created_at', '>=', $since)
            ->sum('amount_sent');
    }

    /** Situation KYC du client : niveau, plafonds et consommation. */
    public function summary(User $user): array
    {
        $limit = KycLimit::find($user->kyc_level);
        $dailyUsed = $this->used($user, now()->startOfDay());
        $monthlyUsed = $this->used($user, now()->startOfMonth());

        return [
            'level' => $user->kyc_level,
            'next_level' => $user->kyc_level < max(config('kyc.levels')) ? $user->kyc_level + 1 : null,
            'limits' => $limit ? [
                'per_transaction' => $limit->per_transaction !== null ? (float) $limit->per_transaction : null,
                'daily' => $limit->daily_limit !== null ? (float) $limit->daily_limit : null,
                'monthly' => $limit->monthly_limit !== null ? (float) $limit->monthly_limit : null,
            ] : null,
            'used' => ['daily' => $dailyUsed, 'monthly' => $monthlyUsed],
            'currency' => $user->wallet?->currency ?? 'XAF',
        ];
    }

    /**
     * Enregistre une demande de passage au niveau supérieur. Une seule demande en attente
     * à la fois ; les pièces vont sur le disque privé.
     *
     * @param  array<string, UploadedFile>  $files  rôle (front, back, selfie, proof) => fichier
     *
     * @throws InvalidArgumentException
     */
    public function submit(User $user, array $data, array $files): KycSubmission
    {
        $target = $user->kyc_level + 1;

        if ($target > max(config('kyc.levels'))) {
            throw new InvalidArgumentException('Vous avez déjà atteint le niveau maximum.');
        }

        if ((int) $data['target_level'] !== $target) {
            throw new InvalidArgumentException("Vous pouvez uniquement demander le niveau {$target}.");
        }

        if (! in_array($data['document_type'], config("kyc.document_types.{$target}", []), true)) {
            throw new InvalidArgumentException('Type de document non accepté pour ce niveau.');
        }

        $disk = config('kyc.disk');

        return DB::transaction(function () use ($user, $data, $files, $target, $disk) {
            // Verrou sur l'utilisateur : deux demandes simultanées ne passent pas.
            User::whereKey($user->id)->lockForUpdate()->first();

            if (KycSubmission::where('user_id', $user->id)->where('status', KycSubmission::PENDING)->exists()) {
                throw new InvalidArgumentException('Une demande est déjà en cours de vérification.');
            }

            $stored = [];
            foreach ($files as $role => $file) {
                $stored[] = [
                    'role' => $role,
                    'disk' => $disk,
                    'path' => Storage::disk($disk)->putFileAs(
                        "kyc/{$user->id}",
                        $file,
                        Str::uuid().'.'.strtolower($file->guessExtension() ?: $file->getClientOriginalExtension())
                    ),
                    'mime' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize(),
                ];
            }

            return KycSubmission::create([
                'user_id' => $user->id,
                'target_level' => $target,
                'document_type' => $data['document_type'],
                'document_number' => $data['document_number'] ?? null,
                'full_name' => $data['full_name'],
                'birth_date' => $data['birth_date'] ?? null,
                'files' => $stored,
            ]);
        });
    }

    /** @throws InvalidArgumentException si la demande n'est plus en attente */
    public function approve(KycSubmission $submission, User $admin): KycSubmission
    {
        return $this->review($submission, $admin, KycSubmission::APPROVED, null);
    }

    /** @throws InvalidArgumentException si la demande n'est plus en attente */
    public function reject(KycSubmission $submission, User $admin, string $reason): KycSubmission
    {
        return $this->review($submission, $admin, KycSubmission::REJECTED, $reason);
    }

    private function review(KycSubmission $submission, User $admin, string $status, ?string $reason): KycSubmission
    {
        return DB::transaction(function () use ($submission, $admin, $status, $reason) {
            $locked = KycSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== KycSubmission::PENDING) {
                throw new InvalidArgumentException('Cette demande a déjà été traitée.');
            }

            $locked->update([
                'status' => $status,
                'rejection_reason' => $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            if ($status === KycSubmission::APPROVED) {
                // Ne baisse jamais un niveau déjà obtenu.
                User::whereKey($locked->user_id)->where('kyc_level', '<', $locked->target_level)
                    ->update(['kyc_level' => $locked->target_level]);
            }

            return $locked;
        });
    }
}
