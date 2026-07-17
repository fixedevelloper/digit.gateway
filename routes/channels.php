<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

/*Broadcast::channel('user.{id}', function ($user, $id) {
    $isAuthorized = (int) $user->id === (int) $id;

    Log::info('[Broadcast Auth] Tentative', [
        'user_id' => $user->id,
        'channel_id' => $id,
        'authorized' => $isAuthorized
    ]);

    return $isAuthorized;
});*/

Broadcast::channel('private-user.{id}', function ($user, $id) {
    Log::info('[Broadcast Auth] Tentative', [
        'user_id' => $user->id,
        'channel_id' => $id,
    ]);
    return true;
});
