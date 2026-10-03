<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

/**
 * Autorisations sur les transferts. Le client ne voit/annule que les siens ; seul un agent
 * peut prendre et traiter un transfert manuel (et uniquement s'il lui est assigné) ;
 * l'admin consulte. Les contrôles d'état (statut, double prise en charge) sont dans
 * ManualTransferService.
 */
class TransferPolicy
{
    public function view(User $user, Transaction $transfer): bool
    {
        return $transfer->user_id === $user->id
            || ($transfer->isManual() && in_array($user->role, ['agent', 'admin', 'superadmin'], true));
    }

    public function own(User $user, Transaction $transfer): bool
    {
        return $transfer->user_id === $user->id;
    }

    public function viewQueue(User $user): bool
    {
        return in_array($user->role, ['agent', 'admin', 'superadmin'], true);
    }

    public function cancel(User $user, Transaction $transfer): bool
    {
        return $transfer->user_id === $user->id;
    }

    public function claim(User $user, Transaction $transfer): bool
    {
        return $user->role === 'agent' && $transfer->isManual();
    }

    public function process(User $user, Transaction $transfer): bool
    {
        return $user->role === 'agent' && $transfer->assigned_agent_id === $user->id;
    }
}
