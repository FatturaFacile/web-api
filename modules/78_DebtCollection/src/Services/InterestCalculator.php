<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Modules\DebtCollection\Exceptions\DebtCollectionException;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Late-payment interest to the cent, with exact decimal arithmetic (no floats).
 *
 * Interest runs for every day after the due date up to and including the calculation date, so a due date of 1 January
 * and a calculation date of 11 January count 10 days. The period is cut wherever the rate changes (the ECB reference
 * rate of D.Lgs. 231/2002 changes every semester) and, on the actual/actual basis, at the turn of the year. Each piece
 * is principal x rate x days / denominator at ten decimals; the sum is rounded once, half away from zero.
 *
 * Bases: actual_365 and actual_360 use a fixed denominator, actual_actual the length of the calendar year of the piece.
 */
final class InterestCalculator
{
    public const string Actual365 = 'actual_365';

    public const string Actual360 = 'actual_360';

    public const string ActualActual = 'actual_actual';

    /** @var list<string> */
    public const array Bases = [self::Actual365, self::Actual360, self::ActualActual];

    /**
     * @param  list<array{from: CarbonInterface, to: CarbonInterface|null, rate: string}>  $periods  ordered rate periods, rate in percent per year
     * @return array{interest: string, days: int, rate_percent: string, basis: string, segments: list<array{from: string, to: string, days: int, rate_percent: string, interest: string}>}
     */
    public function calculate(string $principal, CarbonInterface $dueDate, CarbonInterface $asOf, string $basis, array $periods, ?string $contractualRate = null): array
    {
        if (! in_array($basis, self::Bases, true)) {
            throw new DebtCollectionException("Unknown interest basis [{$basis}].", 'invalid_interest_basis');
        }

        $cursor = Carbon::instance($dueDate)->startOfDay()->addDay();
        $end = Carbon::instance($asOf)->startOfDay();

        if (! Decimal::isPositive($principal) || $cursor->gt($end)) {
            return ['interest' => '0.00', 'days' => 0, 'rate_percent' => $this->latestRate($periods, $end, $contractualRate), 'basis' => $basis, 'segments' => []];
        }

        $total = '0';
        $days = 0;
        $segments = [];

        while ($cursor->lte($end)) {
            $period = $this->periodOn($periods, $cursor);
            $rate = $contractualRate !== null && Decimal::compare($contractualRate, $period['rate']) > 0 ? $contractualRate : $period['rate'];

            $segmentEnd = $end->copy();

            if ($period['to'] !== null && Carbon::instance($period['to'])->startOfDay()->lt($segmentEnd)) {
                $segmentEnd = Carbon::instance($period['to'])->startOfDay();
            }

            $nextStart = $this->nextPeriodStart($periods, $cursor);

            if ($nextStart !== null && $nextStart->copy()->subDay()->lt($segmentEnd)) {
                $segmentEnd = $nextStart->copy()->subDay();
            }

            if ($basis === self::ActualActual && $cursor->copy()->endOfYear()->startOfDay()->lt($segmentEnd)) {
                $segmentEnd = $cursor->copy()->endOfYear()->startOfDay();
            }

            $count = (int) $cursor->diffInDays($segmentEnd, true) + 1;
            $denominator = match ($basis) {
                self::Actual360 => '360',
                self::ActualActual => $cursor->isLeapYear() ? '366' : '365',
                default => '365',
            };

            $piece = Decimal::div(Decimal::mul(Decimal::mul($principal, Decimal::div($rate, '100')), (string) $count), $denominator);
            $total = Decimal::add($total, $piece);
            $days += $count;
            $segments[] = [
                'from' => $cursor->toDateString(),
                'to' => $segmentEnd->toDateString(),
                'days' => $count,
                'rate_percent' => bcadd($rate, '0', 4),
                'interest' => Decimal::round2($piece),
            ];

            $cursor = $segmentEnd->copy()->addDay();
        }

        return [
            'interest' => Decimal::round2($total),
            'days' => $days,
            'rate_percent' => $this->latestRate($periods, $end, $contractualRate),
            'basis' => $basis,
            'segments' => $segments,
        ];
    }

    /**
     * @param  list<array{from: CarbonInterface, to: CarbonInterface|null, rate: string}>  $periods
     * @return array{from: CarbonInterface, to: CarbonInterface|null, rate: string}
     */
    private function periodOn(array $periods, CarbonInterface $day): array
    {
        $found = null;

        foreach ($periods as $period) {
            if (Carbon::instance($period['from'])->startOfDay()->lte($day) && ($period['to'] === null || Carbon::instance($period['to'])->startOfDay()->gte($day))) {
                // The latest starting period wins when two overlap.
                if ($found === null || Carbon::instance($period['from'])->gt(Carbon::instance($found['from']))) {
                    $found = $period;
                }
            }
        }

        if ($found === null) {
            throw new DebtCollectionException('No interest rate is configured for '.$day->toDateString().'.', 'no_interest_rate');
        }

        return $found;
    }

    /**
     * @param  list<array{from: CarbonInterface, to: CarbonInterface|null, rate: string}>  $periods
     */
    private function nextPeriodStart(array $periods, CarbonInterface $day): ?Carbon
    {
        $next = null;

        foreach ($periods as $period) {
            $from = Carbon::instance($period['from'])->startOfDay();

            if ($from->gt($day) && ($next === null || $from->lt($next))) {
                $next = $from;
            }
        }

        return $next;
    }

    /**
     * The annual rate in force on the calculation date (or the last one), as a string with up to four decimals.
     *
     * @param  list<array{from: CarbonInterface, to: CarbonInterface|null, rate: string}>  $periods
     */
    private function latestRate(array $periods, CarbonInterface $day, ?string $contractualRate): string
    {
        $rate = '0';

        try {
            $rate = $this->periodOn($periods, Carbon::instance($day)->startOfDay())['rate'];
        } catch (DebtCollectionException) {
            $rate = $periods === [] ? '0' : $periods[array_key_last($periods)]['rate'];
        }

        if ($contractualRate !== null && Decimal::compare($contractualRate, $rate) > 0) {
            $rate = $contractualRate;
        }

        return bcadd($rate, '0', 4);
    }
}
