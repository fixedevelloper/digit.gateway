<?php

namespace App\Policies;

use App\Models\User;

/**
 * Configuration des pays et de leurs services (services activés, limites, frais, champs
 * bancaires) : administrateurs uniquement (jamais les agents).
 */
class CountryPolicy
{
    public function manage(User $user): bool
    {
        return in_array($user->role, ['admin', 'superadmin'], true);
    }
}
