<?php

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

const USAGE_URL = 'https://pixelforge.test/v1/usage*';

beforeEach(function () {
    // A running Vite dev server would make Inertia call its SSR gateway, which the HTTP fake records.
    config(['inertia.ssr.enabled' => false]);
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
});

/**
 * Pixelforge's GET /v1/usage for the default 30 day window, zero days included.
 *
 * @param  array<string, array{generations: int, cost: string}>  $days
 */
function usageResponse(array $days = []): array
{
    $data = [];
    for ($i = 29; $i >= 0; $i--) {
        $date = Carbon::parse('2026-11-19', 'UTC')->subDays($i)->toDateString();
        $data[] = [
            'date' => $date,
            'generations' => $days[$date]['generations'] ?? 0,
            'cost_usd' => $days[$date]['cost'] ?? '0.000000000',
        ];
    }

    return ['object' => 'usage', 'start_date' => '2026-10-21', 'end_date' => '2026-11-19', 'data' => $data];
}

function reconcilePage(array $query = []): Assert
{
    $result = null;

    test()->actingAs(test()->admin)
        ->get(route('admin.dashboard', $query))
        ->assertInertia(function (Assert $page) use (&$result) {
            $result = $page;

            return $page;
        });

    return $result;
}

test('does not call Pixelforge while the page first renders', function () {
    fakePixelForge([USAGE_URL => Http::response(usageResponse())]);

    $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();

    Http::assertNothingSent();
});

test('asks Pixelforge for the same date range, with our API key', function () {
    fakePixelForge([USAGE_URL => Http::response(usageResponse())]);

    $this->actingAs($this->admin)->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $p) => $p->has('reconciliation')));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/usage')
        && str_contains($request->url(), 'start_date=2026-10-21')
        && str_contains($request->url(), 'end_date=2026-11-19')
        && $request->hasHeader('Authorization', 'Bearer pf_test_key'));
});

test('a day that matches in cost and count is reported as a match', function () {
    $user = User::factory()->create();
    completedGeneration($user, '0.078606250000', '0.062885000000', '2026-11-19 09:00:00');
    fakePixelForge([USAGE_URL => Http::response(usageResponse(['2026-11-19' => ['generations' => 1, 'cost' => '0.062885000']]))]);

    $this->actingAs($this->admin)->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $p) => $p
            ->where('reconciliation.available', true)
            ->where('reconciliation.error', null)
            ->where('reconciliation.matched', 30)
            ->where('reconciliation.differing', 0)
            ->where('reconciliation.days.29.date', '2026-11-19')
            ->where('reconciliation.days.29.ours_cost', '0.062885000000')
            ->where('reconciliation.days.29.theirs_cost', '0.062885000000')
            ->where('reconciliation.days.29.difference', '0.000000000000')
            ->where('reconciliation.days.29.status', 'match')));
});

test('a cost difference is reported with the exact amount', function () {
    $user = User::factory()->create();
    completedGeneration($user, '0.078606250000', '0.062885000000', '2026-11-19 09:00:00');
    fakePixelForge([USAGE_URL => Http::response(usageResponse(['2026-11-19' => ['generations' => 1, 'cost' => '0.070000000']]))]);

    $this->actingAs($this->admin)->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $p) => $p
            ->where('reconciliation.matched', 29)
            ->where('reconciliation.differing', 1)
            ->where('reconciliation.days.29.difference', '-0.007115000000')
            ->where('reconciliation.days.29.status', 'differs')));
});

test('a different generation count is a difference even when the cost matches', function () {
    $user = User::factory()->create();
    completedGeneration($user, '0.078606250000', '0.062885000000', '2026-11-19 09:00:00');
    fakePixelForge([USAGE_URL => Http::response(usageResponse(['2026-11-19' => ['generations' => 2, 'cost' => '0.062885000']]))]);

    $this->actingAs($this->admin)->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $p) => $p
            ->where('reconciliation.days.29.ours_count', 1)
            ->where('reconciliation.days.29.theirs_count', 2)
            ->where('reconciliation.days.29.status', 'differs')));
});

test('a day Pixelforge billed that we have no record of shows our side as zero', function () {
    fakePixelForge([USAGE_URL => Http::response(usageResponse(['2026-11-10' => ['generations' => 1, 'cost' => '0.050000000']]))]);

    $this->actingAs($this->admin)->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $p) => $p
            ->where('reconciliation.days.20.date', '2026-11-10')
            ->where('reconciliation.days.20.ours_cost', '0.000000000000')
            ->where('reconciliation.days.20.difference', '-0.050000000000')
            ->where('reconciliation.days.20.status', 'differs')));
});

test('the user filter does not change reconciliation, which is account wide', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    completedGeneration($a, '0.100000000000', '0.080000000000', '2026-11-19 09:00:00');
    completedGeneration($b, '0.200000000000', '0.160000000000', '2026-11-19 09:00:00');
    fakePixelForge([USAGE_URL => Http::response(usageResponse(['2026-11-19' => ['generations' => 2, 'cost' => '0.240000000']]))]);

    $this->actingAs($this->admin)->get(route('admin.dashboard', ['user' => $a->id]))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $p) => $p
            ->where('reconciliation.days.29.ours_cost', '0.240000000000')
            ->where('reconciliation.days.29.status', 'match')));
});

test('when Pixelforge is unreachable the page still works and says so', function () {
    fakePixelForge([USAGE_URL => Http::response('boom', 500)]);

    $this->actingAs($this->admin)->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('summary')
            ->loadDeferredProps(fn (Assert $p) => $p
                ->where('reconciliation.available', false)
                ->where('reconciliation.days', [])
                ->whereType('reconciliation.error', 'string')));
});

test('a repeated view within a minute reuses the answer instead of calling again', function () {
    Cache::flush();
    fakePixelForge([USAGE_URL => Http::response(usageResponse())]);

    foreach ([1, 2] as $_) {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $p) => $p->has('reconciliation')));
    }

    Http::assertSentCount(1);
});
