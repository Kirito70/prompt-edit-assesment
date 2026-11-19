<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (string) $user->id === (string) $id;
});

// History listens here for any change to the signed in user's generations.
Broadcast::channel('users.{userId}.generations', function (User $user, string $userId) {
    return (string) $user->id === $userId;
});

// The detail page listens here for one generation, and only its owner may.
Broadcast::channel('generations.{generationId}', function (User $user, string $generationId) {
    // Postgres raises an error for a malformed UUID, so reject it before querying.
    if (! Str::isUuid($generationId)) {
        return false;
    }

    return Generation::query()
        ->whereKey($generationId)
        ->where('user_id', $user->id)
        ->exists();
});
