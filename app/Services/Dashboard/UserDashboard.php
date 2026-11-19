<?php

namespace App\Services\Dashboard;

use App\Http\Presenters\GenerationPresenter;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The numbers on a user's dashboard. Money is summed with BCMath so totals are
 * exact on every database (SQLite's SUM would return a float).
 */
final class UserDashboard
{
    public const DAYS = 30;

    private const RECENT = 5;

    private const SCALE = 12;

    private const ZERO = '0.000000000000';

    /** @var array<int, array{total: string, month: string, daily: array<string, string>}> */
    private array $money = [];

    /**
     * @return array{total_spent: string, spent_this_month: string, images: int, in_progress: int, failed: int}
     */
    public function stats(User $user): array
    {
        $counts = $user->generations()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $count = fn (string $status): int => (int) ($counts[$status] ?? 0);
        $money = $this->money($user);

        return [
            'total_spent' => $money['total'],
            'spent_this_month' => $money['month'],
            'images' => $count('succeeded'),
            'in_progress' => $count('queued') + $count('processing'),
            'failed' => $count('failed'),
        ];
    }

    /**
     * One entry per UTC day for the last 30 days, oldest first, zero-filled.
     *
     * @return list<array{date: string, amount: string}>
     */
    public function dailySpend(User $user): array
    {
        $daily = $this->money($user)['daily'];
        $start = $this->windowStart();
        $days = [];

        for ($offset = 0; $offset < self::DAYS; $offset++) {
            $date = $start->copy()->addDays($offset)->toDateString();
            $days[] = ['date' => $date, 'amount' => $daily[$date] ?? self::ZERO];
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
     * One pass over the user's settled charges, shared by stats and the chart.
     *
     * @return array{total: string, month: string, daily: array<string, string>}
     */
    private function money(User $user): array
    {
        if (isset($this->money[$user->id])) {
            return $this->money[$user->id];
        }

        $monthStart = Carbon::now('UTC')->startOfMonth();
        $windowStart = $this->windowStart();
        $total = self::ZERO;
        $month = self::ZERO;
        $daily = [];

        $settled = $user->generations()
            ->whereNotNull('settled_at')
            ->whereNotNull('customer_charge')
            ->select(['id', 'customer_charge', 'settled_at'])
            ->cursor();

        foreach ($settled as $generation) {
            $amount = $generation->customer_charge;

            if (! is_numeric($amount) || $generation->settled_at === null) {
                continue;
            }

            $settledAt = $generation->settled_at->copy()->utc();
            $total = bcadd($total, $amount, self::SCALE);

            if ($settledAt->gte($monthStart)) {
                $month = bcadd($month, $amount, self::SCALE);
            }

            if ($settledAt->gte($windowStart)) {
                $date = $settledAt->toDateString();
                $daily[$date] = bcadd($daily[$date] ?? self::ZERO, $amount, self::SCALE);
            }
        }

        return $this->money[$user->id] = ['total' => $total, 'month' => $month, 'daily' => $daily];
    }

    private function windowStart(): Carbon
    {
        return Carbon::now('UTC')->startOfDay()->subDays(self::DAYS - 1);
    }
}
