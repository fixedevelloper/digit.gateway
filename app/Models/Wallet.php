<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use HasFactory;

    /**
     * Solde fictif offert à l'inscription d'un marchand, et plafond du solde sandbox
     * (rechargeable depuis le portail, cf. Merchant\PortalController::topUpSandbox).
     */
    public const SANDBOX_STARTING_BALANCE = 1_000_000;

    public const SANDBOX_MAX_BALANCE = 100_000_000;

    /**
     * Les attributs qui peuvent être assignés en masse.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'balance',
        'sandbox_balance',
        'currency',
    ];

    /**
     * Cast automatique des attributs.
     * Crucial pour s'assurer que le solde est traité comme un nombre à virgule (float) en PHP.
     */
    protected $casts = [
        'balance' => 'float',
        'sandbox_balance' => 'float',
    ];

    /**
     * Colonne de solde à débiter/créditer selon l'environnement de l'opération :
     * une transaction sandbox ne touche jamais le vrai solde.
     */
    public static function balanceColumn(string $environment): string
    {
        return $environment === 'sandbox' ? 'sandbox_balance' : 'balance';
    }

    /**
     * Obtenir l'utilisateur propriétaire de ce portefeuille.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Historique des ajustements manuels (crédit/débit) effectués par un admin.
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(WalletAdjustment::class);
    }
}
