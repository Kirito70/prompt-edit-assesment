<?php

namespace App\Services\Admin;

use App\Services\PixelForge\Exceptions\PixelForgeException;
use App\Services\PixelForgeService;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Compares our recorded provider cost, per UTC day, with what Pixelforge says
 * it billed for the API key this app uses (GET /v1/usage). A day matches only
 * when the cost is identical to the last decimal and the counts agree.
 */
final class UsageReconciliation
{
    private const CACHE_SECONDS = 60;

    public function __construct(private readonly PixelForgeService $pixelForge) {}

    /**
     * @param  list<array{date: string, generations: int, credits: numeric-string, provider_cost: numeric-string, profit: numeric-string}>  $ours  account-wide daily rows
     * @return array{available: bool, error: string|null, matched: int, differing: int, days: list<array<string, mixed>>}
     */
    public function compare(array $ours, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $from = $start->toDateString();
        $to = $end->toDateString();

        try {
            $usage = Cache::remember(
                "admin.pixelforge-usage.{$from}.{$to}",
                self::CACHE_SECONDS,
                fn (): array => $this->pixelForge->usage($from, $to),
            );
        } catch (PixelForgeException $e) {
            report($e);

            return [
                'available' => false,
                'error' => 'Pixelforge could not be reached, so the comparison is unavailable right now.',
                'matched' => 0,
                'differing' => 0,
                'days' => [],
            ];
        }

        $theirs = [];

        foreach ($usage['data'] ?? [] as $day) {
            if (is_array($day) && is_string($day['date'] ?? null)) {
                $theirs[$day['date']] = [
                    'count' => (int) ($day['generations'] ?? 0),
                    'cost' => Decimal::fromDatabase($day['cost_usd'] ?? null),
                ];
            }
        }

        $days = [];
        $matched = 0;

        foreach ($ours as $day) {
            $their = $theirs[$day['date']] ?? ['count' => 0, 'cost' => Decimal::ZERO];
            $difference = bcsub($day['provider_cost'], $their['cost'], Decimal::SCALE);
            $matches = bccomp($difference, Decimal::ZERO, Decimal::SCALE) === 0 && $day['generations'] === $their['count'];
            $matched += $matches ? 1 : 0;

            $days[] = [
                'date' => $day['date'],
                'ours_cost' => $day['provider_cost'],
                'theirs_cost' => $their['cost'],
                'difference' => $difference,
                'ours_count' => $day['generations'],
                'theirs_count' => $their['count'],
                'status' => $matches ? 'match' : 'differs',
            ];
        }

        return [
            'available' => true,
            'error' => null,
            'matched' => $matched,
            'differing' => count($days) - $matched,
            'days' => $days,
        ];
    }
}
