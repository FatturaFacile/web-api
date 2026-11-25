<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Services;

use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Domain\Enums\ContributionMode;
use Modules\GdoTradeTerms\Domain\Enums\FixedAccrual;
use Modules\GdoTradeTerms\Models\GdoPromotion;
use Modules\GdoTradeTerms\Support\Money;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Pure arithmetic of a trade contribution up to a date. Percent and per-unit contributions read the sell-in of the window
 * (net of returns and credit notes) or the sell-out reported by the retailer; a fixed amount (listing fee, opening, display,
 * Ce.Di. logistics) accrues linearly over the window, or in full when the window starts. A cap limits the total.
 */
final class PromotionContributionCalculator
{
    /**
     * @param  array{value: string, quantity: string}  $sellIn  net sell-in value and quantity of the window up to the date
     * @param  array{value: string, quantity: string}  $sellOut  sell-out reported in the window up to the date
     * @return array{earned: string, base_value: string, rate_percent: string|null}
     */
    public function earned(GdoPromotion $promotion, array $sellIn, array $sellOut, Carbon $asOf): array
    {
        $zero = Money::round('0');
        $base = $zero;
        $earned = $zero;
        $rate = $promotion->rate_percent;

        switch ($promotion->contribution_mode) {
            case ContributionMode::PercentOfSales:
                $base = $this->nonNegative($sellIn['value']);
                $earned = Money::percentOf($base, (string) ($promotion->rate_percent ?? '0'));
                break;
            case ContributionMode::PerUnit:
                $base = $this->nonNegative($sellIn['quantity']);
                $earned = Money::mul($base, (string) ($promotion->unit_amount ?? '0'));
                $rate = null;
                break;
            case ContributionMode::PercentOfSellOut:
                if ($promotion->unit_amount !== null && Money::isPositive((string) $promotion->unit_amount)) {
                    $base = $this->nonNegative($sellOut['quantity']);
                    $earned = Money::mul($base, (string) $promotion->unit_amount);
                    $rate = null;
                } else {
                    $base = $this->nonNegative($sellOut['value']);
                    $earned = Money::percentOf($base, (string) ($promotion->rate_percent ?? '0'));
                }
                break;
            case ContributionMode::FixedAmount:
                $earned = $this->fixed($promotion, $asOf);
                $rate = null;
                break;
        }

        if ($promotion->cap_amount !== null && Money::compare($earned, (string) $promotion->cap_amount) > 0) {
            $earned = Money::round((string) $promotion->cap_amount);
        }

        return ['earned' => $earned, 'base_value' => $base, 'rate_percent' => $rate === null ? null : Money::round((string) $rate)];
    }

    private function fixed(GdoPromotion $promotion, Carbon $asOf): string
    {
        $amount = Money::round((string) ($promotion->fixed_amount ?? '0'));

        if ($asOf->lt($promotion->starts_on)) {
            return Money::round('0');
        }

        if ($promotion->fixed_accrual === FixedAccrual::OnStart || $asOf->gte($promotion->ends_on)) {
            return $amount;
        }

        $total = max(1, (int) $promotion->starts_on->copy()->startOfDay()->diffInDays($promotion->ends_on->copy()->startOfDay()) + 1);
        $elapsed = (int) $promotion->starts_on->copy()->startOfDay()->diffInDays($asOf->copy()->startOfDay()) + 1;

        return Money::round(Decimal::div(Decimal::mul($amount, (string) $elapsed), (string) $total));
    }

    private function nonNegative(string $value): string
    {
        return Money::isPositive($value) ? Money::round($value) : Money::round('0');
    }
}
