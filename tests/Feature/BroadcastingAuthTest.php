<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    // The test environment uses the null broadcaster; use Pusher's signing with
    // throwaway credentials so the real channel authorization runs.
    config([
        'broadcasting.default' => 'pusher',
        'broadcasting.connections.pusher.key' => 'test-key',
        'broadcasting.connections.pusher.secret' => 'test-secret',
        'broadcasting.connections.pusher.app_id' => '1',
    ]);

    // Channels register on whichever broadcaster is the default at boot (null
    // here), so register them again on the Pusher driver now it is the default.
    require base_path('routes/channels.php');
});

function authorizeChannel(string $channel): TestResponse
{
    return test()->post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => $channel,
    ]);
}

test('a user can authorize their own private channel', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = authorizeChannel("private-App.Models.User.{$user->id}");

    $response->assertOk();
    expect($response->json('auth'))->toStartWith('test-key:');
});

test('a user cannot authorize another users private channel', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user);

    authorizeChannel("private-App.Models.User.{$other->id}")->assertForbidden();
});

test('a guest cannot authorize any private channel', function () {
    $user = User::factory()->create();

    authorizeChannel("private-App.Models.User.{$user->id}")->assertForbidden();
});

test('a user can listen to their own generations channel only', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user);

    authorizeChannel("private-users.{$user->id}.generations")->assertOk();
    authorizeChannel("private-users.{$other->id}.generations")->assertForbidden();
});

test('a user can listen to one of their own generations but not someone elses', function () {
    $user = User::factory()->create();
    $mine = Generation::factory()->for($user)->create();
    $theirs = Generation::factory()->create();

    $this->actingAs($user);

    authorizeChannel("private-generations.{$mine->id}")->assertOk();
    authorizeChannel("private-generations.{$theirs->id}")->assertForbidden();
    authorizeChannel('private-generations.'.Str::uuid())->assertForbidden();
});

test('a guest cannot listen to any generation channel', function () {
    $generation = Generation::factory()->create();

    authorizeChannel("private-generations.{$generation->id}")->assertForbidden();
    authorizeChannel("private-users.{$generation->user_id}.generations")->assertForbidden();
});

test('a channel id that is not a UUID is refused without touching the database', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    DB::flushQueryLog();
    DB::enableQueryLog();
    authorizeChannel('private-generations.not-a-uuid')->assertForbidden();
    $generationQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query) => str_contains($query['query'], 'from "generations"'))
        ->count();
    DB::disableQueryLog();

    expect($generationQueries)->toBe(0);
});
