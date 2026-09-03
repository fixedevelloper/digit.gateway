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

#[Fillable(['name', 'password', 'phone', 'transaction_pin', 'email', 'company_name', 'environment', 'status'])]
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
}
