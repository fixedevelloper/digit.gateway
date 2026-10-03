<?php

namespace App\Policies;

use App\Models\User;

/**
 * Configuration des providers : administrateurs uniquement (jamais les agents).
 */
class ProviderPolicy
{
    public function manage(User $user): bool
    {
        return in_array($user->role, ['admin', 'superadmin'], true);
    }
}
