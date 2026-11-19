<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
});

function settled(User $user, string $charge, string $settledAt, array $extra = []): Generation
{
    return Generation::factory()->for($user)->succeeded()->create(array_merge([
        'customer_charge' => $charge,
        'settled_at' => Carbon::parse($settledAt, 'UTC'),
    ], $extra));
}

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('renders stats, 30 days of spend and recent generations', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('stats')
            ->has('dailySpend', 30)
            ->has('recent', 0));
});

describe('stats', function () {
    test('totals are exact and ignore generations that are not settled', function () {
        $user = User::factory()->create();
        settled($user, '0.078606250000', '2026-11-19 09:00:00');
        settled($user, '0.267334375000', '2026-11-18 09:00:00');
        Generation::factory()->for($user)->failed()->create();
        Generation::factory()->for($user)->create();
        Generation::factory()->for($user)->processing()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total_spent', '0.345940625000')
                ->where('stats.images', 2)
                ->where('stats.in_progress', 2)
                ->where('stats.failed', 1));
    });

    test('this month only counts charges settled since the first of the month (UTC)', function () {
        $user = User::factory()->create();
        settled($user, '0.100000000000', '2026-10-31 23:59:59');
        settled($user, '0.200000000000', '2026-11-01 00:00:00');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.spent_this_month', '0.200000000000')
                ->where('stats.total_spent', '0.300000000000'));
    });

    test('a new user starts at zero', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total_spent', '0.000000000000')
                ->where('stats.spent_this_month', '0.000000000000')
                ->where('stats.images', 0)
                ->where('stats.in_progress', 0)
                ->where('stats.failed', 0));
    });
});

test('daily spend covers the last 30 UTC days, oldest first, with empty days as zero', function () {
    $user = User::factory()->create();
    settled($user, '0.050000000000', '2026-11-19 01:00:00');
    settled($user, '0.050000000000', '2026-11-19 11:00:00');
    settled($user, '0.100000000000', '2026-11-10 08:00:00');
    settled($user, '1.000000000000', '2026-10-15 08:00:00');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('dailySpend', 30)
            ->where('dailySpend.0.date', '2026-10-21')
            ->where('dailySpend.0.amount', '0.000000000000')
            ->where('dailySpend.20.date', '2026-11-10')
            ->where('dailySpend.20.amount', '0.100000000000')
            ->where('dailySpend.29.date', '2026-11-19')
            ->where('dailySpend.29.amount', '0.100000000000')
            ->where('stats.total_spent', '1.200000000000'));
});

test('recent shows the five newest generations in the same shape as history', function () {
    $user = User::factory()->create();
    $ids = collect(range(1, 7))->map(fn (int $i) => Generation::factory()->for($user)->create([
        'created_at' => Carbon::parse('2026-11-19 00:00:00', 'UTC')->addMinutes($i),
    ])->id);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('recent', 5)
            ->where('recent.0.id', $ids->last())
            ->has('recent.0', fn (Assert $row) => $row
                ->hasAll([
                    'id', 'prompt', 'size', 'quality', 'status', 'estimated_cost',
                    'actual_cost', 'image_url', 'image_url_expires_at', 'error_message', 'created_at',
                ])
                ->missing('provider_idempotency_key')
                ->missing('provider_generation_id')
                ->missing('provider_cost')
                ->missing('rates')
                ->missing('usage')));
});

test('another users generations never appear anywhere on the dashboard', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    settled($other, '9.000000000000', '2026-11-19 09:00:00');
    Generation::factory()->for($other)->processing()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_spent', '0.000000000000')
            ->where('stats.images', 0)
            ->where('stats.in_progress', 0)
            ->where('dailySpend.29.amount', '0.000000000000')
            ->has('recent', 0));
});

test('every dashboard prop refreshes live on the users private generations channel', function () {
    $user = User::factory()->create();

    $live = $this->actingAs($user)->get(route('dashboard'))->viewData('page')['liveProps'] ?? [];

    expect(array_keys($live))->toEqualCanonicalizing(['stats', 'dailySpend', 'recent'])
        ->and(json_encode($live))->toContain("users.{$user->id}.generations")
        ->and(json_encode($live))->toContain('generation.updated');
});

describe('aggregation happens in the database', function () {
    test('totals never load the settled generations into PHP', function () {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->count(60)->create([
            'customer_charge' => '0.010000000000',
            'settled_at' => Carbon::parse('2026-11-18 09:00:00', 'UTC'),
        ]);

        $loaded = 0;
        Generation::retrieved(function () use (&$loaded) {
            $loaded++;
        });

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total_spent', '0.600000000000')
                ->where('stats.images', 60));

        // Only the 5 "recent" rows are ever hydrated, however much history exists.
        expect($loaded)->toBeLessThanOrEqual(5);
    });

    test('the number of queries does not grow with the amount of history', function () {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->create([
            'customer_charge' => '0.010000000000',
            'settled_at' => Carbon::parse('2026-11-18 09:00:00', 'UTC'),
        ]);

        $queries = function () use ($user): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user)->get(route('dashboard'))->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $few = $queries();

        Generation::factory()->for($user)->succeeded()->count(80)->create([
            'customer_charge' => '0.010000000000',
            'settled_at' => Carbon::parse('2026-11-17 09:00:00', 'UTC'),
        ]);

        expect($queries())->toBe($few);
    });

    test('many tiny charges add up exactly, with no floating point drift', function () {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->count(1000)->create([
            'customer_charge' => '0.000006250000',
            'settled_at' => Carbon::parse('2026-11-19 08:00:00', 'UTC'),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total_spent', '0.006250000000')
                ->where('stats.spent_this_month', '0.006250000000')
                ->where('dailySpend.29.amount', '0.006250000000'));
    });
});
