<?php

declare(strict_types=1);

namespace Modules\Forecasting\Domain\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Forecasting\Domain\ValueObjects\StatutoryCashEvent;

/**
 * Builds the daily cash buckets of a projection.
 *
 * Uncertainty comes from the collection date of each receivable: the pessimistic path (p10) collects every invoice at its
 * latest plausible date, the expected path (p50) at its expected date and the optimistic path (p90) at its earliest. The
 * three cumulative receipts are therefore ordered on every day, and so are the balances. Payables, recurring items and
 * statutory events are certain. Interest is charged on a negative balance at the extra annual rate of the scenario.
 *
 * Receivable: {amount, earliest, expected, latest} with dates as Y-m-d. Payable: {amount, date}. Recurring: {amount, date, direction}.
 * A date on or before today falls on the first day of the horizon (the cash is late, not lost); a date after the horizon is ignored.
 */
final class LiquidityProjectionService
{
    private const array Scenarios = ['p10' => 'latest', 'p50' => 'expected', 'p90' => 'earliest'];

    /**
     * @param  list<array{amount: float, earliest: string, expected: string, latest: string}>  $receivables
     * @param  list<array{amount: float, date: string}>  $payables
     * @param  list<array{amount: float, date: string, direction: string}>  $recurring
     * @param  list<StatutoryCashEvent>  $statutoryEvents
     * @return list<array{date: string, inflows: array{p10: float, p50: float, p90: float}, outflows: array{p10: float, p50: float, p90: float}, statutory_outflows: list<array<string, mixed>>, statutory_total: float, financing_cost: array{p10: float, p50: float, p90: float}, balance: array{p10: float, p50: float, p90: float}}>
     */
    public function project(
        DateTimeImmutable $today,
        int $horizonDays,
        float $startingCash,
        array $receivables,
        array $payables,
        array $recurring,
        array $statutoryEvents,
        int $customerDelayShiftDays = 0,
        float $revenueGrowthPercentage = 0.0,
        int $supplierExtensionDays = 0,
        float $interestRateDelta = 0.0,
    ): array {
        if ($horizonDays < 1) {
            throw new InvalidArgumentException('The horizon must be at least one day.');
        }

        $today = $today->setTime(0, 0);
        $revenueFactor = 1.0 + $revenueGrowthPercentage / 100.0;
        $inflows = ['p10' => [], 'p50' => [], 'p90' => []];
        $outflows = [];
        $statutory = [];

        foreach ($receivables as $receivable) {
            foreach (self::Scenarios as $band => $field) {
                $day = $this->dayIndex($today, $receivable[$field], $customerDelayShiftDays, $horizonDays);

                if ($day !== null) {
                    $inflows[$band][$day] = ($inflows[$band][$day] ?? 0.0) + $receivable['amount'] * $revenueFactor;
                }
            }
        }

        foreach ($payables as $payable) {
            $day = $this->dayIndex($today, $payable['date'], $supplierExtensionDays, $horizonDays);

            if ($day !== null) {
                $outflows[$day] = ($outflows[$day] ?? 0.0) + $payable['amount'];
            }
        }

        foreach ($recurring as $item) {
            $day = $this->dayIndex($today, $item['date'], 0, $horizonDays);

            if ($day === null) {
                continue;
            }

            if ($item['direction'] === 'inflow') {
                foreach (array_keys(self::Scenarios) as $band) {
                    $inflows[$band][$day] = ($inflows[$band][$day] ?? 0.0) + $item['amount'] * $revenueFactor;
                }
            } else {
                $outflows[$day] = ($outflows[$day] ?? 0.0) + $item['amount'];
            }
        }

        foreach ($statutoryEvents as $event) {
            $day = $this->dayIndex($today, $event->date->format('Y-m-d'), 0, $horizonDays);

            if ($day !== null) {
                $statutory[$day][] = $event;
            }
        }

        $dailyRate = max(0.0, $interestRateDelta) / 100.0 / 365.0;
        $balances = array_fill_keys(array_keys(self::Scenarios), $startingCash);
        $buckets = [];

        for ($day = 1; $day <= $horizonDays; $day++) {
            $statutoryToday = $statutory[$day] ?? [];
            $statutoryTotal = array_sum(array_map(static fn (StatutoryCashEvent $event): float => $event->amount, $statutoryToday));
            $outflow = ($outflows[$day] ?? 0.0);
            $bucket = ['inflows' => [], 'financing_cost' => [], 'balance' => []];

            foreach (array_keys(self::Scenarios) as $band) {
                $interest = $balances[$band] < 0.0 ? -$balances[$band] * $dailyRate : 0.0;
                $in = $inflows[$band][$day] ?? 0.0;
                $balances[$band] += $in - $outflow - $statutoryTotal - $interest;
                $bucket['inflows'][$band] = round($in, 2);
                $bucket['financing_cost'][$band] = round($interest, 2);
                $bucket['balance'][$band] = round($balances[$band], 2);
            }

            $buckets[] = [
                'date' => $today->modify("+{$day} days")->format('Y-m-d'),
                'inflows' => $bucket['inflows'],
                'outflows' => ['p10' => round($outflow, 2), 'p50' => round($outflow, 2), 'p90' => round($outflow, 2)],
                'statutory_outflows' => array_map(static fn (StatutoryCashEvent $event): array => $event->toArray(), $statutoryToday),
                'statutory_total' => round($statutoryTotal, 2),
                'financing_cost' => $bucket['financing_cost'],
                'balance' => $bucket['balance'],
            ];
        }

        return $buckets;
    }

    /**
     * Day of the horizon (1 = tomorrow) a date falls on after the shift, or null when it is beyond the horizon.
     */
    private function dayIndex(DateTimeImmutable $today, string $date, int $shiftDays, int $horizonDays): ?int
    {
        $shifted = (new DateTimeImmutable($date))->setTime(0, 0)->modify(sprintf('%+d days', $shiftDays));
        $day = (int) $today->diff($shifted)->format('%r%a');
        $day = max(1, $day);

        return $day > $horizonDays ? null : $day;
    }
}
