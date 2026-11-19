<?php

namespace App\Services\Dashboard;

use App\Http\Presenters\GenerationPresenter;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The numbers on a user's dashboard, all computed by the database.
 *
 * Amounts are rounded to 12 decimals inside the query. Postgres sums NUMERIC
 * exactly; SQLite (used by the tests) sums floats, and ROUND removes any float
 * noise before the value is turned back into an exact decimal string.
 */
final class UserDashboard
{
    public const DAYS = 30;

    private const RECENT = 5;

    private const SCALE = 12;

    private const ZERO = '0.000000000000';

    /**
     * @return array{total_spent: string, spent_this_month: string, images: int, in_progress: int, failed: int}
     */
    public function stats(User $user): array
    {
        $monthStart = Carbon::now('UTC')->startOfMonth()->toDateTimeString();

        $row = $user->generations()
            ->toBase()
            ->selectRaw(
                "count(case when status = 'succeeded' then 1 end) as images,
                 count(case when status in ('queued', 'processing') then 1 end) as in_progress,
                 count(case when status = 'failed' then 1 end) as failed,
                 round(coalesce(sum(case when settled_at is not null then customer_charge end), 0), ?) as total_spent,
                 round(coalesce(sum(case when settled_at >= ? then customer_charge end), 0), ?) as spent_this_month",
                [self::SCALE, $monthStart, self::SCALE],
            )
            ->first();

        // An aggregate without GROUP BY always returns exactly one row.
        return [
            'total_spent' => $this->decimal($row->total_spent),
            'spent_this_month' => $this->decimal($row->spent_this_month),
            'images' => (int) $row->images,
            'in_progress' => (int) $row->in_progress,
            'failed' => (int) $row->failed,
        ];
    }

    /**
     * One entry per UTC day for the last 30 days, oldest first, zero-filled.
     *
     * @return list<array{date: string, amount: string}>
     */
    public function dailySpend(User $user): array
    {
        $start = Carbon::now('UTC')->startOfDay()->subDays(self::DAYS - 1);

        $spent = $user->generations()
            ->toBase()
            ->whereNotNull('settled_at')
            ->where('settled_at', '>=', $start->toDateTimeString())
            ->selectRaw('date(settled_at) as day, round(sum(customer_charge), ?) as amount', [self::SCALE])
            ->groupBy('day')
            ->pluck('amount', 'day');

        $days = [];

        for ($offset = 0; $offset < self::DAYS; $offset++) {
            $date = $start->copy()->addDays($offset)->toDateString();
            $days[] = ['date' => $date, 'amount' => $this->decimal($spent[$date] ?? null)];
        }

        return $days;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(User $user): array
    {
        return array_values($user->generations()
            ->latest()
            ->limit(self::RECENT)
            ->get()
            ->map(fn (Generation $generation): array => GenerationPresenter::row($generation))
            ->all());
    }

    /**
     * A database number as an exact 12-decimal string. Postgres returns NUMERIC
     * as a string (kept exact); SQLite returns a float (already rounded in SQL).
     */
    private function decimal(mixed $value): string
    {
        if (is_float($value)) {
            return is_finite($value) ? number_format($value, self::SCALE, '.', '') : self::ZERO;
        }

        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            return bcadd((string) $value, '0', self::SCALE);
        }

        return self::ZERO;
    }
}
