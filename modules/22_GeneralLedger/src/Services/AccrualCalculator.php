<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Deterministic multi-period accrual and deferral engine (Swiss CO 958b & Italian Art. 2423-bis c.c. / OIC 18).
 *
 * Uses exact inclusive calendar day counts and integer minor-unit (cent) Largest-Remainder (Hamilton) apportionment
 * via bcmath so multi-year splits always sum to the exact total amount without penny loss or floating-point drift.
 */
final readonly class AccrualCalculator
{
    /**
     * Exact calendar days between two dates, inclusive of both start and end.
     */
    public function inclusiveDays(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $from = $start->startOfDay();
        $to = $end->startOfDay();

        if ($to->lessThan($from)) {
            return 0;
        }

        return (int) $from->diffInDays($to) + 1;
    }

    /**
     * Exact calendar days of [$start, $end] overlapping [$windowStart, $windowEnd], inclusive.
     */
    public function overlapDays(
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): int {
        $from = $start->startOfDay()->greaterThan($windowStart->startOfDay())
            ? $start->startOfDay()
            : $windowStart->startOfDay();
        $to = $end->startOfDay()->lessThan($windowEnd->startOfDay())
            ? $end->startOfDay()
            : $windowEnd->startOfDay();

        return $this->inclusiveDays($from, $to);
    }

    /**
     * Infers the transitory classification (rateo/risconto attivo/passivo) from direction, document date,
     * competence window and target fiscal year window. Returns null when no transitory adjustment applies.
     *
     * @param  'cost'|'revenue'|null  $direction
     */
    public function inferClassification(
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $fyStart,
        CarbonImmutable $fyEnd,
        ?CarbonImmutable $documentDate,
        ?string $direction,
    ): ?string {
        if ($direction !== 'cost' && $direction !== 'revenue') {
            return null;
        }

        $startDay = $start->startOfDay();
        $endDay = $end->startOfDay();
        $fyStartDay = $fyStart->startOfDay();
        $fyEndDay = $fyEnd->startOfDay();
        $docDay = $documentDate?->startOfDay();

        // No overlap with the fiscal year at all -> only deferral if billed/recorded in this FY for future periods
        if ($docDay !== null && $docDay->greaterThan($fyEndDay)) {
            // Document issued/recorded in a subsequent period covering days inside [$fyStart, $fyEnd] -> Rateo (Accrual)
            if (! $startDay->greaterThan($fyEndDay) && ! $endDay->lessThan($fyStartDay)) {
                return $direction === 'revenue' ? 'accrued_revenue' : 'accrued_expense';
            }

            return null;
        }

        // Document recorded on or before FY end (or no document date provided):
        // If competence extends beyond FY end, the post-cutoff share is a Risconto (Deferral).
        if ($endDay->greaterThan($fyEndDay)) {
            return $direction === 'cost' ? 'prepaid_expense' : 'deferred_revenue';
        }

        return null;
    }

    /**
     * Computes exact day ratios, multi-year schedule (via Largest-Remainder apportionment), current-year recognized
     * amount and year-end transitory adjustment amount.
     *
     * @param  'cost'|'revenue'|null  $direction
     * @return array{
     *   accrual_type: ?string,
     *   total_amount: string,
     *   coverage_start: string,
     *   coverage_end: string,
     *   cutoff_date: string,
     *   reversal_date: string,
     *   total_days: int,
     *   period_days: int,
     *   transitory_days: int,
     *   recognized_amount: string,
     *   transitory_amount: string,
     *   yearly_schedule: list<array{label: string, start_date: string, end_date: string, days: int, amount: string, is_current_year: bool, is_future: bool}>
     * }
     */
    public function calculate(
        string|int $totalAmount,
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $fyStart,
        CarbonImmutable $fyEnd,
        ?CarbonImmutable $documentDate = null,
        ?string $direction = null,
        ?string $explicitType = null,
    ): array {
        $startDay = $start->startOfDay();
        $endDay = $end->startOfDay();
        $fyStartDay = $fyStart->startOfDay();
        $fyEndDay = $fyEnd->startOfDay();
        $normalizedTotal = Decimal::round2((string) $totalAmount);

        $totalDays = $this->inclusiveDays($startDay, $endDay);
        $periodDays = $this->overlapDays($startDay, $endDay, $fyStartDay, $fyEndDay);
        $type = $explicitType ?? $this->inferClassification($startDay, $endDay, $fyStartDay, $fyEndDay, $documentDate, $direction);

        $slices = $this->buildYearSlices($startDay, $endDay, $fyStartDay, $fyEndDay);
        $allocatedCents = $this->allocateByLargestRemainder($normalizedTotal, $totalDays, array_column($slices, 'days'));

        $yearlySchedule = [];
        $recognizedCents = 0;
        $futureCents = 0;
        $futureDays = 0;

        foreach ($slices as $index => $slice) {
            $cents = $allocatedCents[$index] ?? 0;
            if ($slice['is_current_year']) {
                $recognizedCents += $cents;
            } elseif ($slice['is_future']) {
                $futureCents += $cents;
                $futureDays += $slice['days'];
            }

            $yearlySchedule[] = [
                'label' => $slice['label'],
                'start_date' => $slice['start_date'],
                'end_date' => $slice['end_date'],
                'days' => $slice['days'],
                'amount' => $this->centsToDecimal($cents),
                'is_current_year' => $slice['is_current_year'],
                'is_future' => $slice['is_future'],
            ];
        }

        if ($type === 'accrued_revenue' || $type === 'accrued_expense') {
            $transitoryDays = $periodDays;
            $transitoryCents = $recognizedCents;
        } elseif ($type === 'prepaid_expense' || $type === 'deferred_revenue') {
            $transitoryDays = $futureDays;
            $transitoryCents = $futureCents;
        } else {
            $transitoryDays = 0;
            $transitoryCents = 0;
        }

        return [
            'accrual_type' => $type,
            'total_amount' => $normalizedTotal,
            'coverage_start' => $startDay->toDateString(),
            'coverage_end' => $endDay->toDateString(),
            'cutoff_date' => $fyEndDay->toDateString(),
            'reversal_date' => $fyEndDay->addDay()->toDateString(),
            'total_days' => $totalDays,
            'period_days' => $periodDays,
            'transitory_days' => $transitoryDays,
            'recognized_amount' => $this->centsToDecimal($recognizedCents),
            'transitory_amount' => $this->centsToDecimal($transitoryCents),
            'yearly_schedule' => $yearlySchedule,
        ];
    }

    /**
     * Partitions [$start, $end] into contiguous fiscal-year slices aligned to [$fyStart, $fyEnd].
     *
     * @return list<array{label: string, start_date: string, end_date: string, days: int, is_current_year: bool, is_future: bool}>
     */
    private function buildYearSlices(
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $fyStart,
        CarbonImmutable $fyEnd,
    ): array {
        if ($end->lessThan($start)) {
            return [];
        }

        $isCalendarYear = $fyStart->month === 1 && $fyStart->day === 1
            && $fyEnd->month === 12 && $fyEnd->day === 31
            && $fyStart->year === $fyEnd->year;

        if ($isCalendarYear) {
            return $this->buildCalendarYearSlices($start, $end, $fyStart->year);
        }

        $slices = [];
        $cursorStart = $fyStart;
        $cursorEnd = $fyEnd;

        while ($cursorStart->greaterThan($start)) {
            $cursorEnd = $cursorStart->subDay();
            $cursorStart = $cursorStart->subYearNoOverflow();
        }

        while (! $cursorStart->greaterThan($end)) {
            $overlapStart = $start->greaterThan($cursorStart) ? $start : $cursorStart;
            $overlapEnd = $end->lessThan($cursorEnd) ? $end : $cursorEnd;

            if (! $overlapEnd->lessThan($overlapStart)) {
                $isCurrent = $cursorStart->equalTo($fyStart) && $cursorEnd->equalTo($fyEnd);
                $isFuture = $cursorStart->greaterThan($fyEnd);
                $slices[] = [
                    'label' => $cursorStart->year === $cursorEnd->year
                        ? (string) $cursorStart->year
                        : $cursorStart->format('Y').'/'.$cursorEnd->format('y'),
                    'start_date' => $overlapStart->toDateString(),
                    'end_date' => $overlapEnd->toDateString(),
                    'days' => $this->inclusiveDays($overlapStart, $overlapEnd),
                    'is_current_year' => $isCurrent,
                    'is_future' => $isFuture,
                ];
            }

            $cursorStart = $cursorEnd->addDay();
            $cursorEnd = $cursorStart->addYearNoOverflow()->subDay();
        }

        return $slices;
    }

    /**
     * @return list<array{label: string, start_date: string, end_date: string, days: int, is_current_year: bool, is_future: bool}>
     */
    private function buildCalendarYearSlices(CarbonImmutable $start, CarbonImmutable $end, int $targetYear): array
    {
        $slices = [];

        for ($year = $start->year; $year <= $end->year; $year++) {
            $yearStart = CarbonImmutable::create($year, 1, 1)->startOfDay();
            $yearEnd = CarbonImmutable::create($year, 12, 31)->startOfDay();
            $overlapStart = $start->greaterThan($yearStart) ? $start : $yearStart;
            $overlapEnd = $end->lessThan($yearEnd) ? $end : $yearEnd;

            $slices[] = [
                'label' => (string) $year,
                'start_date' => $overlapStart->toDateString(),
                'end_date' => $overlapEnd->toDateString(),
                'days' => $this->inclusiveDays($overlapStart, $overlapEnd),
                'is_current_year' => $year === $targetYear,
                'is_future' => $year > $targetYear,
            ];
        }

        return $slices;
    }

    /**
     * Largest-Remainder (Hamilton) allocation in integer cents so parts sum exactly to $totalAmount.
     *
     * @param  list<int>  $sliceDays
     * @return list<int>
     */
    private function allocateByLargestRemainder(string $totalAmount, int $totalDays, array $sliceDays): array
    {
        if ($sliceDays === [] || $totalDays <= 0) {
            return array_fill(0, count($sliceDays), 0);
        }

        $totalCentsStr = bcdiv(bcmul($totalAmount, '100', 4), '1', 0);
        $negative = str_starts_with($totalCentsStr, '-');
        $absTotalCents = (int) ltrim($totalCentsStr, '-');

        $floors = [];
        $remainders = [];
        $floorSum = 0;

        foreach ($sliceDays as $index => $days) {
            // Exact rational cents = absTotalCents * days / totalDays
            $numerator = bcmul((string) $absTotalCents, (string) $days, 0);
            $exact = bcdiv($numerator, (string) $totalDays, 12);
            $floorPart = (int) bcdiv($numerator, (string) $totalDays, 0);
            $remainder = bcsub($exact, (string) $floorPart, 12);

            $floors[$index] = $floorPart;
            $remainders[] = ['index' => $index, 'remainder' => $remainder];
            $floorSum += $floorPart;
        }

        $residual = $absTotalCents - $floorSum;

        usort($remainders, static function (array $left, array $right): int {
            $cmp = bccomp($right['remainder'], $left['remainder'], 12);

            return $cmp !== 0 ? $cmp : ($left['index'] <=> $right['index']);
        });

        for ($i = 0; $i < $residual && $i < count($remainders); $i++) {
            $floors[$remainders[$i]['index']]++;
        }

        if ($negative) {
            return array_map(static fn (int $cents): int => -$cents, $floors);
        }

        return $floors;
    }

    private function centsToDecimal(int $cents): string
    {
        $negative = $cents < 0;
        $abs = abs($cents);
        $units = intdiv($abs, 100);
        $rem = $abs % 100;

        return ($negative ? '-' : '').$units.'.'.str_pad((string) $rem, 2, '0', STR_PAD_LEFT);
    }
}
