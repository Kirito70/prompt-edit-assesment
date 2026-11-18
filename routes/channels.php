<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (string) $user->id === (string) $id;
});

// History listens here for any change to the signed in user's generations.
Broadcast::channel('users.{userId}.generations', function (User $user, string $userId) {
    return (string) $user->id === $userId;
});

// The detail page listens here for one generation, and only its owner may.
Broadcast::channel('generations.{generationId}', function (User $user, string $generationId) {
    return Generation::query()
        ->whereKey($generationId)
        ->where('user_id', $user->id)
        ->exists();
});
