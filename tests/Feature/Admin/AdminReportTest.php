<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
});

function adminPage(array $query = []): TestResponse
{
    return test()->actingAs(test()->admin)->get(route('admin.dashboard', $query));
}

describe('range', function () {
    test('defaults to the last 30 UTC days ending today', function () {
        adminPage()->assertInertia(fn (Assert $page) => $page
            ->where('filters.start', '2026-10-21')
            ->where('filters.end', '2026-11-19')
            ->where('filters.user', null)
            ->has('daily', 30)
            ->where('daily.0.date', '2026-10-21')
            ->where('daily.29.date', '2026-11-19'));
    });

    test('follows the requested dates, zero-filling days with no activity', function () {
        adminPage(['start' => '2026-11-10', 'end' => '2026-11-12'])
            ->assertInertia(fn (Assert $page) => $page
                ->has('daily', 3)
                ->where('daily.1.date', '2026-11-11')
                ->where('daily.1.credits', '0.000000000000')
                ->where('daily.1.generations', 0));
    });

    test('rejects bad ranges', function (array $query, string $field) {
        adminPage($query)->assertSessionHasErrors($field);
    })->with([
        'end before start' => [['start' => '2026-11-19', 'end' => '2026-11-01'], 'end'],
        'longer than 92 days (Pixelforge limit)' => [['start' => '2026-06-01', 'end' => '2026-11-19'], 'end'],
        '93 days, one over the limit' => [['start' => '2026-08-19', 'end' => '2026-11-19'], 'end'],
        'not a date' => [['start' => 'yesterday-ish', 'end' => '2026-11-19'], 'start'],
        'unknown user' => [['user' => 999999], 'user'],
    ]);

    test('accepts exactly 92 days', function () {
        adminPage(['start' => '2026-08-20', 'end' => '2026-11-19'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('daily', 92));
    });
});

describe('daily totals', function () {
    test('are exact and grouped by the UTC day the generation completed', function () {
        $user = User::factory()->create();
        completedGeneration($user, '0.100000000000', '0.080000000000', '2026-11-18 23:59:59');
        completedGeneration($user, '0.200000000000', '0.160000000000', '2026-11-19 00:00:00');

        adminPage()->assertInertia(fn (Assert $page) => $page
            ->where('daily.28.date', '2026-11-18')
            ->where('daily.28.credits', '0.100000000000')
            ->where('daily.28.provider_cost', '0.080000000000')
            ->where('daily.28.profit', '0.020000000000')
            ->where('daily.28.generations', 1)
            ->where('daily.29.date', '2026-11-19')
            ->where('daily.29.credits', '0.200000000000')
            ->where('daily.29.provider_cost', '0.160000000000')
            ->where('daily.29.profit', '0.040000000000')
            ->where('summary.credits', '0.300000000000')
            ->where('summary.provider_cost', '0.240000000000')
            ->where('summary.profit', '0.060000000000')
            ->where('summary.generations', 2));
    });

    test('use the real per generation amounts with no float drift', function () {
        $user = User::factory()->create();
        completedGeneration($user, '0.078606250000', '0.062885000000', '2026-11-19 09:00:00');
        completedGeneration($user, '0.267334375000', '0.213867500000', '2026-11-19 10:00:00');

        adminPage()->assertInertia(fn (Assert $page) => $page
            ->where('summary.credits', '0.345940625000')
            ->where('summary.provider_cost', '0.276752500000')
            ->where('summary.profit', '0.069188125000'));
    });

    test('failed generations cost nothing and are not counted as generations', function () {
        $user = User::factory()->create();
        completedGeneration($user, '0.100000000000', '0.080000000000', '2026-11-19 09:00:00');
        Generation::factory()->for($user)->failed()->create([
            'provider_completed_at' => Carbon::parse('2026-11-19 09:30:00', 'UTC'),
        ]);

        adminPage()->assertInertia(fn (Assert $page) => $page
            ->where('summary.generations', 1)
            ->where('summary.credits', '0.100000000000')
            ->where('summary.provider_cost', '0.080000000000'));
    });

    test('ignore generations that have not completed and ones outside the range', function () {
        $user = User::factory()->create();
        Generation::factory()->for($user)->processing()->create();
        completedGeneration($user, '5.000000000000', '4.000000000000', '2026-10-01 09:00:00');
        completedGeneration($user, '0.100000000000', '0.080000000000', '2026-11-19 09:00:00');

        adminPage()->assertInertia(fn (Assert $page) => $page
            ->where('summary.credits', '0.100000000000')
            ->where('summary.generations', 1));
    });
});

describe('by user', function () {
    test('lists each active user with their totals, biggest spender first', function () {
        $small = User::factory()->create(['name' => 'Small Spender', 'email' => 'small@example.com']);
        $big = User::factory()->create(['name' => 'Big Spender', 'email' => 'big@example.com']);
        User::factory()->create(['email' => 'idle@example.com']);
        completedGeneration($small, '0.100000000000', '0.080000000000', '2026-11-19 09:00:00');
        completedGeneration($big, '0.300000000000', '0.240000000000', '2026-11-19 09:00:00');
        completedGeneration($big, '0.200000000000', '0.160000000000', '2026-11-18 09:00:00');

        adminPage()->assertInertia(fn (Assert $page) => $page
            ->has('users', 2)
            ->where('users.0.id', $big->id)
            ->where('users.0.email', 'big@example.com')
            ->where('users.0.generations', 2)
            ->where('users.0.credits', '0.500000000000')
            ->where('users.0.provider_cost', '0.400000000000')
            ->where('users.0.profit', '0.100000000000')
            ->where('users.1.id', $small->id));
    });

    test('filtering by a user scopes the totals and the daily rows to them', function () {
        $a = User::factory()->create(['name' => 'Ann', 'email' => 'ann@example.com']);
        $b = User::factory()->create();
        completedGeneration($a, '0.100000000000', '0.080000000000', '2026-11-19 09:00:00');
        completedGeneration($b, '0.900000000000', '0.700000000000', '2026-11-19 09:00:00');

        adminPage(['user' => $a->id])->assertInertia(fn (Assert $page) => $page
            ->where('filters.user.id', $a->id)
            ->where('filters.user.email', 'ann@example.com')
            ->where('summary.credits', '0.100000000000')
            ->where('daily.29.credits', '0.100000000000'));
    });
});

test('prompts, image urls and provider ids are never sent to the admin page', function () {
    $user = User::factory()->create();
    completedGeneration($user, '0.100000000000', '0.080000000000', '2026-11-19 09:00:00', [
        'prompt' => 'a very private prompt about my secret project',
        'provider_generation_id' => 'gen_PRIVATEID123',
    ]);
    Generation::factory()->for($user)->create(['created_at' => now()->subHour(), 'prompt' => 'another private prompt']);

    $json = json_encode(adminPage()->viewData('page')['props']);

    expect($json)->not->toContain('private prompt')
        ->and($json)->not->toContain('PRIVATEID123')
        ->and($json)->not->toContain('image_url');
});
