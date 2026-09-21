<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Clé API d'un marchand (usage serveur-à-serveur, distincte des tokens Sanctum
 * utilisés par l'app Flutter / le dashboard). La valeur en clair n'est jamais
 * stockée : seul son hash (key_hash) l'est, et elle n'est révélée qu'une
 * seule fois, au moment de sa génération (cf. generateFor()).
 */
#[Fillable(['user_id', 'name', 'key_prefix', 'key_hash', 'environment', 'scopes', 'last_used_at', 'revoked_at'])]
#[Hidden(['key_hash'])]
class ApiKey extends Model
{
    /**
     * Scopes disponibles à l'attribution sur une clé. Vérifiés par
     * ApiKeyAuth::handle() via le paramètre de middleware sur chaque route.
     */
    public const SCOPES = [
        'transfer.write',
        'withdrawal.write',
        'deposit.write',
        'wallet.read',
        'transactions.read',
        'countries.read',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Génère une nouvelle clé pour un marchand et la persiste (hash uniquement).
     *
     * @return array{model: self, plainTextKey: string}
     */
    public static function generateFor(User $user, string $name, string $environment, array $scopes): array
    {
        $prefix = $environment === 'production' ? 'sk_live_' : 'sk_test_';
        $plainTextKey = $prefix.Str::random(40);

        $model = self::create([
            'user_id' => $user->id,
            'name' => $name,
            'key_prefix' => substr($plainTextKey, 0, 12),
            'key_hash' => hash('sha256', $plainTextKey),
            'environment' => $environment,
            'scopes' => $scopes,
        ]);

        return ['model' => $model, 'plainTextKey' => $plainTextKey];
    }

    public static function findByPlainTextKey(string $plainTextKey): ?self
    {
        return self::where('key_hash', hash('sha256', $plainTextKey))
            ->whereNull('revoked_at')
            ->first();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }
}
