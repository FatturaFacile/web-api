<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Services;

use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Domain\Enums\BaselineSource;
use Modules\GdoTradeTerms\Domain\Enums\ContributionMode;
use Modules\GdoTradeTerms\Domain\Enums\DateBasis;
use Modules\GdoTradeTerms\Domain\Enums\PromotionStatus;
use Modules\GdoTradeTerms\Domain\Enums\RebateBasis;
use Modules\GdoTradeTerms\Domain\Enums\VolumeMode;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Models\GdoPromotion;
use Modules\GdoTradeTerms\Models\GdoScale;
use Modules\GdoTradeTerms\Support\Money;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * What a rebate scale or a promotion has earned up to a date: the qualifying sales read from the invoices, run through the
 * calculators. Single source of truth for the accrual runs, the settlements, the progress and the margin views.
 */
final readonly class EarningsService
{
    public function __construct(
        private SalesBaseResolver $sales,
        private RebateScaleCalculator $scales,
        private PromotionContributionCalculator $promotions,
    ) {}

    /**
     * @return array{earned: string, base_value: string, rate_percent: string|null, volume: string, baseline: string, qualifying: string, tier: int|null}
     */
    public function scaleEarned(GdoScale $scale, GdoContract $contract, Carbon $asOf): array
    {
        $zero = Money::round('0');
        $empty = ['earned' => $zero, 'base_value' => $zero, 'rate_percent' => null, 'volume' => $zero, 'baseline' => $zero, 'qualifying' => $zero, 'tier' => null];

        if (! $scale->is_active || $asOf->lt($scale->windowStart($contract))) {
            return $empty;
        }

        $volume = $this->sales->scaleVolume($scale, $contract, $asOf)['volume'];
        $baseline = $scale->volume_mode === VolumeMode::Incremental ? $this->baselineToDate($scale, $contract, $asOf) : $zero;
        $qualifying = $this->scales->qualifying($volume, $baseline, $scale->volume_mode);
        $earned = $this->scales->earn($scale->tierRows(), $qualifying, $scale->tier_mode);

        return ['earned' => $earned['rebate'], 'base_value' => $qualifying, 'rate_percent' => $earned['rate_percent'], 'volume' => $volume, 'baseline' => $baseline, 'qualifying' => $qualifying, 'tier' => $earned['tier']];
    }

    /**
     * Year-end projection of a scale, with the figures it is built from.
     *
     * @return array<string, mixed>
     */
    public function scaleProjection(GdoScale $scale, GdoContract $contract, Carbon $asOf): array
    {
        $volume = $this->sales->scaleVolume($scale, $contract, $asOf)['volume'];
        $baseline = $scale->volume_mode === VolumeMode::Incremental ? $this->sales->baseline($scale, $contract) : Money::round('0');

        return $this->scales->projection($scale->tierRows(), $scale->tier_mode, $scale->volume_mode, $volume, $baseline, $scale->windowStart($contract), $scale->windowEnd($contract), $asOf) + [
            'baseline' => $baseline,
            'basis' => $scale->basis->value,
        ];
    }

    /**
     * @return array{earned: string, base_value: string, rate_percent: string|null}
     */
    public function promotionEarned(GdoPromotion $promotion, GdoContract $contract, Carbon $asOf): array
    {
        $zero = Money::round('0');

        if (in_array($promotion->status, [PromotionStatus::Draft, PromotionStatus::Cancelled], true) || $asOf->lt($promotion->starts_on)) {
            return ['earned' => $zero, 'base_value' => $zero, 'rate_percent' => null];
        }

        $none = ['value' => $zero, 'quantity' => $zero];
        $sellIn = in_array($promotion->contribution_mode, [ContributionMode::PercentOfSales, ContributionMode::PerUnit], true) ? $this->sales->promotionSellIn($promotion, $contract, $asOf) : $none;
        $sellOut = $promotion->contribution_mode === ContributionMode::PercentOfSellOut ? $this->sales->promotionSellOut($promotion, $contract, $asOf) : $none;

        return $this->promotions->earned($promotion, $sellIn, $sellOut, $asOf);
    }

    /**
     * The baseline an incremental scale is compared with at a date: what the previous year's window sold up to the same day, or
     * the agreed figure spread evenly over the window.
     */
    private function baselineToDate(GdoScale $scale, GdoContract $contract, Carbon $asOf): string
    {
        $start = $scale->windowStart($contract);
        $end = $scale->windowEnd($contract);

        if ($asOf->gte($end)) {
            return $this->sales->baseline($scale, $contract);
        }

        if ($scale->baseline_source === BaselineSource::PriorPeriod) {
            $sales = $this->sales->sellIn($contract, $start->copy()->subYear(), $asOf->copy()->subYear(), DateBasis::Invoice, $this->sales->scopeOf($scale));

            return $scale->basis === RebateBasis::Quantity ? $sales['quantity'] : $sales['value'];
        }

        $total = max(1, (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1);
        $elapsed = (int) $start->copy()->startOfDay()->diffInDays($asOf->copy()->startOfDay()) + 1;

        return Money::round(Decimal::div(Decimal::mul((string) $scale->baseline_value, (string) $elapsed), (string) $total));
    }
}
