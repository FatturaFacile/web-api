<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Services;

use Modules\AssetManagement\Models\AssetBook;
use Modules\AssetManagement\Models\DepreciationSchedule;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Support\AssetLedger;
use Modules\AssetManagement\Support\DepreciationPeriod;
use Modules\AssetManagement\Support\Money;

/**
 * Builds a unified multi-year parallel depreciation comparison (Civil/Statutory vs Fiscal CH AFC / IT D.M. 1988),
 * combining already posted schedules with projected future periods, deferred-tax temporary differences, and
 * Transition 4.0 / 5.0 or Super/Iper-Ammortamento incentive schedules.
 */
final readonly class ParallelDepreciationScheduleService
{
    public function __construct(
        private AssetBookService $books,
        private DepreciationCalculator $calculator,
        private FiscalIncentiveCalculator $incentives,
    ) {}

    /**
     * @return array{
     *   asset_id: string,
     *   asset_number: string,
     *   fiscal_jurisdiction: ?string,
     *   period_type: string,
     *   books: array{statutory: array<string, mixed>, tax: array<string, mixed>},
     *   incentive: array<string, mixed>,
     *   rows: list<array<string, mixed>>,
     *   totals: array<string, string>
     * }
     */
    public function compare(FixedAsset $asset, string $periodType = 'yearly', int $maxPeriods = 30): array
    {
        $type = in_array($periodType, DepreciationPeriod::Types, true) ? $periodType : 'yearly';
        $maxPeriods = max(1, min(120, $maxPeriods));

        $civilBook = $this->books->bookFor($asset, AssetLedger::Statutory, false);
        $taxBook = $this->books->bookFor($asset, AssetLedger::Tax, false);

        $civilTimeline = $this->timelineForBook($asset, $civilBook, AssetLedger::Statutory, $type, $maxPeriods);
        $taxTimeline = $this->timelineForBook($asset, $taxBook, AssetLedger::Tax, $type, $maxPeriods);

        $periodCodes = array_values(array_unique([...array_keys($civilTimeline), ...array_keys($taxTimeline)]));
        sort($periodCodes);
        $periodCodes = array_slice($periodCodes, 0, $maxPeriods);

        $incentive = $this->incentives->computeSummary($asset);
        $rows = [];

        $civilClosing = $this->initialCost($civilBook, $asset);
        $civilAccum = '0.00';
        $fiscalClosing = $this->initialCost($taxBook, $asset);
        $fiscalAccum = '0.00';

        $totalCivil = '0.00';
        $totalFiscal = '0.00';
        $totalTaxCredit = '0.00';
        $totalExtraDeduction = '0.00';

        foreach ($periodCodes as $code) {
            $c = $civilTimeline[$code] ?? null;
            $f = $taxTimeline[$code] ?? null;

            $civilOpen = $c['opening_book_value'] ?? $civilClosing;
            $civilDepr = $c['depreciation_amount'] ?? '0.00';
            $civilClose = $c['closing_book_value'] ?? $civilOpen;
            $civilAcc = $c['accumulated_depreciation'] ?? $civilAccum;

            $fiscalOpen = $f['opening_book_value'] ?? $fiscalClosing;
            $fiscalDepr = $f['depreciation_amount'] ?? '0.00';
            $fiscalClose = $f['closing_book_value'] ?? $fiscalOpen;
            $fiscalAcc = $f['accumulated_depreciation'] ?? $fiscalAccum;

            $civilClosing = $civilClose;
            $civilAccum = $civilAcc;
            $fiscalClosing = $fiscalClose;
            $fiscalAccum = $fiscalAcc;

            $year = (int) substr($code, 0, 4);
            $taxCredit = $type === 'yearly' ? ($incentive['annual_installments'][$year] ?? '0.00') : '0.00';
            $extraDeduction = $this->incentives->extraDeductionForCharge($asset, $fiscalDepr);

            $tempDiff = Money::round(Money::sub($fiscalDepr, $civilDepr));
            $cumTempDiff = Money::round(Money::sub($civilClose, $fiscalClose));

            $totalCivil = Money::round(Money::add($totalCivil, $civilDepr));
            $totalFiscal = Money::round(Money::add($totalFiscal, $fiscalDepr));
            $totalTaxCredit = Money::round(Money::add($totalTaxCredit, $taxCredit));
            $totalExtraDeduction = Money::round(Money::add($totalExtraDeduction, $extraDeduction));

            $rows[] = [
                'period_code' => $code,
                'is_posted_civil' => (bool) ($c['is_posted'] ?? false),
                'is_posted_fiscal' => (bool) ($f['is_posted'] ?? false),
                'civil_opening' => Money::round($civilOpen),
                'civil_depreciation' => Money::round($civilDepr),
                'civil_accumulated' => Money::round($civilAcc),
                'civil_closing' => Money::round($civilClose),
                'fiscal_opening' => Money::round($fiscalOpen),
                'fiscal_depreciation' => Money::round($fiscalDepr),
                'fiscal_accumulated' => Money::round($fiscalAcc),
                'fiscal_closing' => Money::round($fiscalClose),
                'temporary_difference' => $tempDiff,
                'cumulative_temporary_difference' => $cumTempDiff,
                'incentive_tax_credit' => Money::round($taxCredit),
                'incentive_extra_deduction' => Money::round($extraDeduction),
            ];
        }

        return [
            'asset_id' => (string) $asset->id,
            'asset_number' => (string) $asset->asset_number,
            'fiscal_jurisdiction' => $asset->fiscal_jurisdiction,
            'period_type' => $type,
            'books' => [
                'statutory' => $this->bookMeta($civilBook),
                'tax' => $this->bookMeta($taxBook),
            ],
            'incentive' => $incentive,
            'rows' => $rows,
            'totals' => [
                'civil_depreciation' => $totalCivil,
                'fiscal_depreciation' => $totalFiscal,
                'temporary_difference' => Money::round(Money::sub($totalFiscal, $totalCivil)),
                'incentive_tax_credit' => $totalTaxCredit,
                'incentive_extra_deduction' => $totalExtraDeduction,
            ],
        ];
    }

    /**
     * @return array<string, array{opening_book_value: string, depreciation_amount: string, closing_book_value: string, accumulated_depreciation: string, is_posted: bool}>
     */
    private function timelineForBook(FixedAsset $asset, AssetBook $book, string $ledgerType, string $periodType, int $maxPeriods): array
    {
        $rows = [];
        $lastPostedEnd = null;

        $posted = DepreciationSchedule::where('tenant_id', $asset->tenant_id)
            ->where('asset_id', $asset->id)
            ->where('ledger_type', $ledgerType)
            ->orderBy('period_code')
            ->get();

        foreach ($posted as $schedule) {
            $code = (string) $schedule->period_code;
            $key = $periodType === 'yearly' ? substr($code, 0, 4) : $code;

            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'opening_book_value' => Money::round($schedule->opening_book_value),
                    'depreciation_amount' => Money::round($schedule->depreciation_amount),
                    'closing_book_value' => Money::round($schedule->closing_book_value),
                    'accumulated_depreciation' => Money::round($schedule->accumulated_depreciation),
                    'is_posted' => true,
                ];
            } else {
                $rows[$key]['depreciation_amount'] = Money::round(Money::add($rows[$key]['depreciation_amount'], $schedule->depreciation_amount));
                $rows[$key]['closing_book_value'] = Money::round($schedule->closing_book_value);
                $rows[$key]['accumulated_depreciation'] = Money::round($schedule->accumulated_depreciation);
            }

            try {
                $end = DepreciationPeriod::fromCode($code, (string) $schedule->period_type)->end;
                if ($lastPostedEnd === null || $end->gt($lastPostedEnd)) {
                    $lastPostedEnd = $end;
                }
            } catch (\InvalidArgumentException) {
                // Ignore non-standard historical period code
            }
        }

        if (! $book->is_fully_depreciated && $book->depreciation_start_date !== null) {
            $from = $lastPostedEnd !== null
                ? DepreciationPeriod::containing($lastPostedEnd->addDay(), $periodType)
                : DepreciationPeriod::containing($book->depreciation_start_date, $periodType);

            $remainingSlots = max(1, $maxPeriods - count($rows));
            foreach ($this->calculator->project($book, $from, $remainingSlots) as $projected) {
                $code = $projected['period_code'];
                if (! isset($rows[$code])) {
                    $rows[$code] = [
                        'opening_book_value' => $projected['opening_book_value'],
                        'depreciation_amount' => $projected['depreciation_amount'],
                        'closing_book_value' => $projected['closing_book_value'],
                        'accumulated_depreciation' => $projected['accumulated_depreciation'],
                        'is_posted' => false,
                    ];
                }
            }
        }

        return $rows;
    }

    private function initialCost(AssetBook $book, FixedAsset $asset): string
    {
        if (Money::isPositive($book->cost)) {
            return Money::round($book->cost);
        }

        return Money::round(Money::isPositive($asset->capitalized_cost) ? $asset->capitalized_cost : $asset->acquisition_cost);
    }

    /**
     * @return array<string, mixed>
     */
    private function bookMeta(AssetBook $book): array
    {
        return [
            'ledger_type' => $book->ledger_type,
            'fiscal_regime' => $book->fiscal_regime,
            'fiscal_rate_id' => $book->fiscal_rate_id,
            'depreciation_method' => $book->depreciation_method,
            'rate_percent' => $book->rate_percent !== null ? (string) $book->rate_percent : $this->calculator->annualRatePercent($book),
            'useful_life_months' => (int) $book->useful_life_months,
            'pro_rata_convention' => $book->pro_rata_convention,
            'first_year_half_rate' => (bool) $book->first_year_half_rate,
            'immediate_expensing' => (bool) $book->immediate_expensing,
            'switch_to_straight_line' => (bool) $book->switch_to_straight_line,
            'pro_memoria_value' => $book->pro_memoria_value !== null ? (string) $book->pro_memoria_value : null,
            'cost' => Money::round($book->cost),
            'accumulated_depreciation' => Money::round($book->accumulated_depreciation),
            'net_book_value' => Money::round($book->net_book_value),
        ];
    }
}
