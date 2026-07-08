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

#[Fillable(['name', 'email', 'password', 'phone', 'api_key', 'currency'])] // <- Ajout de phone, api_key et currency
#[Hidden(['password', 'remember_token', 'api_key'])] // <- On cache aussi l'api_key des réponses JSON par sécurité
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable,HasApiTokens;

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
                'currency' => $user->currency ?? 'XAF',
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
