<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Postgres rejects a malformed UUID with a database error (a 500), while SQLite
 * quietly finds nothing. So the id is checked before any query is made.
 */
function generationQueries(callable $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $count = collect(DB::getQueryLog())
        ->filter(fn (array $query) => str_contains($query['query'], 'from "generations"'))
        ->count();
    DB::disableQueryLog();

    return $count;
}

test('an id that is not a UUID is a 404 and never reaches the database', function (string $id) {
    $user = User::factory()->create();

    $queries = generationQueries(fn () => $this->actingAs($user)->get('/history/'.$id)->assertNotFound());

    expect($queries)->toBe(0);
})->with([
    'plain text' => ['not-a-uuid'],
    'number' => ['12345'],
    'almost a uuid' => ['0198-uuid-not-quite'],
]);

test('a well formed UUID that does not exist is still a 404', function () {
    $this->actingAs(User::factory()->create())
        ->get('/history/'.Str::uuid())
        ->assertNotFound();
});

test('a real generation of the user is still found', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create();

    $this->actingAs($user)->get(route('history.show', $generation))->assertOk();
});
