<?php

namespace App\Services\Admin;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * What the operators see: money in and out per UTC day and per user, plus what
 * needs attention. Sums are done by the database and grouped by the day a
 * generation COMPLETED, which is the day Pixelforge bills it, so the provider
 * cost can be compared with Pixelforge's own usage report.
 *
 * @phpstan-type Figures array{generations: int, credits: numeric-string, provider_cost: numeric-string, profit: numeric-string}
 * @phpstan-type DayRow array{date: string, generations: int, credits: numeric-string, provider_cost: numeric-string, profit: numeric-string}
 * @phpstan-type UserRow array{id: int, name: string, email: string, generations: int, credits: numeric-string, provider_cost: numeric-string, profit: numeric-string}
 */
final class AdminReport
{
    private const USERS_LIMIT = 200;

    private const STUCK_AFTER_MINUTES = 15;

    private const STUCK_LISTED = 10;

    private const EVENT_PENDING_AFTER_MINUTES = 5;

    /**
     * One row per day in the range, zero-filled, oldest first.
     *
     * @return list<DayRow>
     */
    public function daily(CarbonImmutable $start, CarbonImmutable $end, ?int $userId = null): array
    {
        $rows = $this->completed($start, $end)
            ->when($userId !== null, fn (Builder $query) => $query->where('generations.user_id', $userId))
            ->selectRaw(
                "date(generations.provider_completed_at) as day,
                 count(case when generations.status = 'succeeded' then 1 end) as generations,
                 round(coalesce(sum(generations.customer_charge), 0), ?) as credits,
                 round(coalesce(sum(generations.provider_cost), 0), ?) as provider_cost",
                [Decimal::SCALE, Decimal::SCALE],
            )
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $days = [];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $row = $rows->get($date->toDateString());

            $days[] = [
                'date' => $date->toDateString(),
                ...$this->figures($row->generations ?? 0, $row->credits ?? null, $row->provider_cost ?? null),
            ];
        }

        return $days;
    }

    /**
     * @param  list<DayRow>  $daily
     * @return Figures
     */
    public function summary(array $daily): array
    {
        $generations = 0;
        $credits = Decimal::ZERO;
        $cost = Decimal::ZERO;

        foreach ($daily as $day) {
            $generations += $day['generations'];
            $credits = bcadd($credits, $day['credits'], Decimal::SCALE);
            $cost = bcadd($cost, $day['provider_cost'], Decimal::SCALE);
        }

        return [
            'generations' => $generations,
            'credits' => $credits,
            'provider_cost' => $cost,
            'profit' => bcsub($credits, $cost, Decimal::SCALE),
        ];
    }

    /**
     * Every user with billed activity in the range, biggest spender first.
     *
     * @return list<UserRow>
     */
    public function users(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->completed($start, $end)
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->selectRaw(
                "users.id, users.name, users.email,
                 count(case when generations.status = 'succeeded' then 1 end) as generations,
                 round(coalesce(sum(generations.customer_charge), 0), ?) as credits,
                 round(coalesce(sum(generations.provider_cost), 0), ?) as provider_cost",
                [Decimal::SCALE, Decimal::SCALE],
            )
            ->groupBy('users.id', 'users.name', 'users.email')
            ->havingRaw("count(case when generations.status = 'succeeded' then 1 end) > 0")
            ->orderByDesc('credits')
            ->orderBy('users.id')
            ->limit(self::USERS_LIMIT)
            ->get();

        $users = [];

        foreach ($rows as $row) {
            $users[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                ...$this->figures($row->generations, $row->credits, $row->provider_cost),
            ];
        }

        return $users;
    }

    /**
     * Things that make the numbers wrong or late: generations that never
     * finished and webhook events that were never applied.
     *
     * @return array{
     *     stuck_total: int,
     *     stuck: list<array{id: string, email: string, status: string, submission_status: string, minutes: int}>,
     *     events_unprocessed: int,
     *     events_with_errors: int,
     * }
     */
    public function attention(): array
    {
        $stuck = fn () => Generation::query()
            ->toBase()
            ->whereNull('generations.settled_at')
            ->whereIn('generations.status', ['queued', 'processing'])
            ->where('generations.created_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES)->toDateTimeString());

        $rows = $stuck()
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->orderBy('generations.created_at')
            ->limit(self::STUCK_LISTED)
            ->get(['generations.id', 'generations.status', 'generations.submission_status', 'generations.created_at', 'users.email']);

        $listed = [];

        foreach ($rows as $row) {
            $listed[] = [
                'id' => (string) $row->id,
                'email' => (string) $row->email,
                'status' => (string) $row->status,
                'submission_status' => (string) $row->submission_status,
                'minutes' => (int) floor(Carbon::parse($row->created_at, 'UTC')->diffInMinutes(now('UTC'), true)),
            ];
        }

        $unprocessed = fn () => WebhookEvent::query()->whereNull('processed_at');

        return [
            'stuck_total' => $stuck()->count(),
            'stuck' => $listed,
            'events_unprocessed' => $unprocessed()
                ->where('received_at', '<', now()->subMinutes(self::EVENT_PENDING_AFTER_MINUTES))
                ->count(),
            'events_with_errors' => $unprocessed()->whereNotNull('processing_error')->count(),
        ];
    }

    /**
     * Generations that completed inside the range (UTC days, end inclusive).
     */
    private function completed(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return Generation::query()
            ->toBase()
            ->whereNotNull('generations.provider_completed_at')
            ->where('generations.provider_completed_at', '>=', $start->startOfDay()->toDateTimeString())
            ->where('generations.provider_completed_at', '<', $end->addDay()->startOfDay()->toDateTimeString());
    }

    /**
     * The money columns shared by every row, with profit worked out exactly.
     *
     * @return Figures
     */
    private function figures(mixed $generations, mixed $credits, mixed $cost): array
    {
        $credits = Decimal::fromDatabase($credits);
        $cost = Decimal::fromDatabase($cost);

        return [
            'generations' => is_numeric($generations) ? (int) $generations : 0,
            'credits' => $credits,
            'provider_cost' => $cost,
            'profit' => bcsub($credits, $cost, Decimal::SCALE),
        ];
    }
}
