<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany; // <- Import manquant ajouté
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'password', 'phone', 'transaction_pin', 'email', 'company_name', 'environment', 'status', 'role', 'terms_version', 'terms_accepted_at', 'privacy_version', 'privacy_accepted_at'])]
#[Hidden(['password', 'remember_token', 'transaction_pin'])] // <- On cache aussi l'api_key des réponses JSON par sécurité
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory,Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'transaction_pin' => 'hashed',
            'status' => 'boolean',
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
        ];
    }

    /**
     * Déclenché automatiquement à la création d'un utilisateur.
     */
    protected static function booted(): void
    {
        static::created(function ($user) {
            // Crée automatiquement un portefeuille dès qu'un utilisateur est enregistré
            $user->wallet()->create([
                'balance' => 0.00,
                'currency' => 'XAF',
            ]);
        });
    }

    public function legalAcceptances(): HasMany
    {
        return $this->hasMany(LegalAcceptance::class);
    }

    /**
     * Vrai tant que l'utilisateur n'a pas accepté les versions en vigueur des CGU et de la politique de confidentialité.
     */
    public function legalAcceptanceRequired(): bool
    {
        $accepted = $this->legalAcceptances()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('document', 'terms')->where('version', config('legal.terms_version')))
                ->orWhere(fn ($q) => $q->where('document', 'privacy')->where('version', config('legal.privacy_version'))))
            ->count();

        return $accepted < 2;
    }

    /**
     * Enregistre l'acceptation (historique + dernier état sur la ligne user). Horodatage fixé par le serveur.
     */
    public function recordLegalAcceptance(string $termsVersion, string $privacyVersion, ?string $ip = null, ?string $userAgent = null): void
    {
        $now = now();
        $meta = ['accepted_at' => $now, 'ip_address' => $ip, 'user_agent' => $userAgent ? substr($userAgent, 0, 255) : null];

        $this->legalAcceptances()->updateOrCreate(['document' => 'terms', 'version' => $termsVersion], $meta);
        $this->legalAcceptances()->updateOrCreate(['document' => 'privacy', 'version' => $privacyVersion], $meta);

        $this->forceFill([
            'terms_version' => $termsVersion,
            'terms_accepted_at' => $now,
            'privacy_version' => $privacyVersion,
            'privacy_accepted_at' => $now,
        ])->save();
    }

    /**
     * Relation avec le portefeuille (Wallet).
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * Relation avec les bénéficiaires (Recipients).
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(Recipient::class);
    }

    /**
     * Clés API du compte marchand (espace self-service SaaS).
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }
}
