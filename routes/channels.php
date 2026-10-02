<?php

use Illuminate\Support\Facades\Broadcast;

// Canal "private-user.{id}" : statut des transactions d'un utilisateur, réservé à
// cet utilisateur (TransactionStatusUpdated).
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id && (bool) $user->status;
});
