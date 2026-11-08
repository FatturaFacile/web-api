<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Services;

use Carbon\CarbonImmutable;
use Modules\AssetManagement\Models\AssetBook;
use Modules\AssetManagement\Support\DepreciationPeriod;
use Modules\AssetManagement\Support\Money;

/**
 * Pure depreciation arithmetic on one asset book (no persistence). Rates and conventions come from the book, which
 * takes them from the asset or its category: nothing fiscal is hard-coded here.
 *
 * Methods:
 * - straight_line: annual charge = depreciable base x 12 / useful life in months, or depreciable base x rate when the
 *   book has a rate (the tax book with a configured fiscal coefficient). The civil book is driven by the useful life.
 * - declining_balance / double_declining_balance: annual charge = net book value x rate x factor (1 and 2 unless the
 *   book sets another factor), switching to straight line over the remaining life when that charges more.
 * - units_of_production: depreciable base x units of the period / total expected units.
 *
 * Pro-rata conventions for the period containing the depreciation start date (first year for half_year):
 * - full: the whole period charge;
 * - monthly: the months in service of the period, the start month counted in full;
 * - daily: the days in service of the period;
 * - half_year: half of the annual charge over the first calendar year, spread over its months in service.
 * The charge never takes the net book value below the salvage value.
 */
final class DepreciationCalculator
{
    public const array Methods = ['straight_line', 'declining_balance', 'double_declining_balance', 'units_of_production'];

    public const array Conventions = ['full', 'monthly', 'daily', 'half_year'];

    /** Upper bound of projected periods, against books that would never reach their salvage value. */
    private const int MaxProjectedPeriods = 1200;

    /**
     * Depreciation charge of the book for a period, rounded to cents.
     */
    public function periodCharge(AssetBook $book, DepreciationPeriod $period, string|int|float $unitsProduced = 0): string
    {
        $remaining = Money::sub($book->net_book_value, $book->salvage_value);
        $start = $book->depreciation_start_date;

        if (! Money::isPositive($remaining) || $start === null || $period->endsBefore($start)) {
            return '0.00';
        }

        $method = in_array($book->depreciation_method, self::Methods, true) ? $book->depreciation_method : 'straight_line';

        if ($method === 'units_of_production') {
            $charge = Money::isPositive($book->total_expected_units)
                ? Money::div(Money::mul($book->depreciable_base, $unitsProduced), $book->total_expected_units)
                : '0';

            return Money::round(Money::min($remaining, Money::max('0', $charge)));
        }

        $annualRate = Money::div($this->annualRatePercent($book), '100');
        $annual = match (true) {
            $method !== 'straight_line' => $this->decliningAnnualCharge($book, $method, $annualRate, $remaining, $period),
            Money::isPositive($book->rate_percent) => Money::mul($book->depreciable_base, $annualRate),
            default => Money::div(Money::mul($book->depreciable_base, '12'), max(1, (int) $book->useful_life_months)),
        };

        $charge = $this->applyConvention($book, $period, $annual);

        return Money::round(Money::min($remaining, Money::max('0', $charge)));
    }

    /**
     * Projects the depreciation of the book from a period on, without persisting anything, until the book is fully
     * depreciated. Units of production need the expected units of each period.
     *
     * @return list<array{period_code: string, opening_book_value: string, depreciation_amount: string, closing_book_value: string, accumulated_depreciation: string}>
     */
    public function project(AssetBook $book, DepreciationPeriod $from, int $maxPeriods = 120, string|int|float $unitsPerPeriod = 0): array
    {
        $book = clone $book;
        $rows = [];
        $period = $from;
        $limit = min($maxPeriods, self::MaxProjectedPeriods);

        for ($index = 0; $index < $limit; $index++) {
            $opening = Money::round($book->net_book_value);
            $charge = $this->periodCharge($book, $period, $unitsPerPeriod);

            if (Money::isPositive($charge) || $rows !== [] || ! $this->startsAfter($book, $period)) {
                $book->net_book_value = Money::round(Money::sub($opening, $charge));
                $book->accumulated_depreciation = Money::round(Money::add($book->accumulated_depreciation, $charge));
                $book->consumed_units = Money::round(Money::add($book->consumed_units, $unitsPerPeriod));

                $rows[] = [
                    'period_code' => $period->code,
                    'opening_book_value' => $opening,
                    'depreciation_amount' => $charge,
                    'closing_book_value' => $book->net_book_value,
                    'accumulated_depreciation' => $book->accumulated_depreciation,
                ];
            }

            if (Money::compare($book->net_book_value, $book->salvage_value) <= 0 || ($rows !== [] && ! Money::isPositive($charge))) {
                break;
            }

            $period = $period->next();
        }

        return $rows;
    }

    /**
     * Annual rate in percent: the book rate, else 100 / useful life in years.
     */
    public function annualRatePercent(AssetBook $book): string
    {
        if (Money::isPositive($book->rate_percent)) {
            return Money::of($book->rate_percent);
        }

        return Money::div('1200', max(1, (int) $book->useful_life_months));
    }

    /**
     * Declining balance charge, switching to straight line over the remaining life when that is higher.
     */
    private function decliningAnnualCharge(AssetBook $book, string $method, string $annualRate, string $remaining, DepreciationPeriod $period): string
    {
        $factor = Money::isPositive($book->declining_balance_factor)
            ? Money::of($book->declining_balance_factor)
            : ($method === 'double_declining_balance' ? '2' : '1');

        $declining = Money::mul(Money::mul($book->net_book_value, $annualRate), $factor);
        $start = $book->depreciation_start_date;
        $elapsed = $start === null ? 0 : max(0, ($period->start->year - $start->year) * 12 + $period->start->month - $start->month);
        $remainingMonths = (int) $book->useful_life_months - $elapsed;

        if ($remainingMonths <= 0) {
            return Money::mul($remaining, '12');
        }

        return Money::max($declining, Money::div(Money::mul($remaining, '12'), $remainingMonths));
    }

    /**
     * Applies the period length and the pro-rata convention of the first period(s) to an annual charge.
     */
    private function applyConvention(AssetBook $book, DepreciationPeriod $period, string $annual): string
    {
        $start = $book->depreciation_start_date;
        $periodCharge = Money::div(Money::mul($annual, $period->months), '12');

        if ($start === null) {
            return '0';
        }

        $convention = in_array($book->pro_rata_convention, self::Conventions, true) ? $book->pro_rata_convention : 'monthly';

        if ($convention === 'half_year') {
            if ($period->end->year !== $start->year) {
                return $periodCharge;
            }

            $firstYearMonths = 12 - $start->month + 1;
            $monthsInService = $this->monthsInService($period, $start);

            return Money::div(Money::mul(Money::div($annual, '2'), $monthsInService), $firstYearMonths);
        }

        if ($convention === 'full' || ! $period->contains($start)) {
            return $periodCharge;
        }

        if ($convention === 'daily') {
            $daysInPeriod = (int) $period->start->diffInDays($period->end) + 1;
            $daysInService = (int) $start->diffInDays($period->end) + 1;

            return Money::div(Money::mul($periodCharge, $daysInService), $daysInPeriod);
        }

        return Money::div(Money::mul($periodCharge, $this->monthsInService($period, $start)), $period->months);
    }

    /**
     * Months of the period from the start month on (the start month counted in full).
     */
    private function monthsInService(DepreciationPeriod $period, CarbonImmutable $start): int
    {
        if (! $period->contains($start)) {
            return $period->start->gte($start) ? $period->months : 0;
        }

        return ($period->end->year - $start->year) * 12 + $period->end->month - $start->month + 1;
    }

    /**
     * Whether the depreciation of the book starts after the period.
     */
    private function startsAfter(AssetBook $book, DepreciationPeriod $period): bool
    {
        return $book->depreciation_start_date !== null && $period->endsBefore($book->depreciation_start_date);
    }
}
