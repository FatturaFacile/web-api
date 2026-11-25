<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Support;

use Carbon\CarbonImmutable;
use Modules\FranchiseNetwork\Domain\Enums\StartupMode;

/**
 * Monthly royalty and national marketing fund of one store from its net sell-out.
 *
 *  1. royalty and marketing fund come from progressive brackets on the net sales of the month;
 *  2. during the start-up months after the opening the royalty (and the fund, when the formula says so) is reduced by the start-up
 *     factor: 0 for a full exemption, `1 - reduction` for a reduced rate, a linear ramp from `ramp_start_percent` towards 100 %;
 *  3. the monthly minimum guarantee (scaled by the same factor, so it is suspended during a full exemption) lifts the royalty up
 *     to the guaranteed amount: charged = max(royalty after start-up, minimum guarantee); the difference is shown as an adjustment.
 */
final class RoyaltyCalculator
{
    /**
     * @param  array{royalty_tiers: list<array{from: string|int|float, to: string|int|float|null, rate: string|int|float}>, marketing_tiers: list<array{from: string|int|float, to: string|int|float|null, rate: string|int|float}>, minimum_guarantee: string|int|float, startup_months: int, startup_mode: string, startup_reduction_percent: string|int|float, ramp_start_percent: string|int|float, startup_applies_to_marketing: bool}  $formula
     * @return array<string, mixed>
     */
    public static function calculate(string|int|float $netSales, array $formula, ?CarbonImmutable $openedOn, string $period): array
    {
        $sales = Dec::cmp($netSales, 0) < 0 ? '0' : Dec::of($netSales);
        $royalty = TieredRateCalculator::calculate($sales, $formula['royalty_tiers']);
        $marketing = TieredRateCalculator::calculate($sales, $formula['marketing_tiers']);

        $month = self::startupMonth($openedOn, $period);
        $factor = self::startupFactor($formula, $month);
        $marketingFactor = $formula['startup_applies_to_marketing'] ? $factor : '1';

        $royaltyAfterStartup = Dec::round(Dec::mul($royalty['amount'], $factor));
        $guarantee = Dec::round(Dec::mul($formula['minimum_guarantee'], $factor));
        $charged = Dec::max($royaltyAfterStartup, $guarantee);
        $marketingCharged = Dec::round(Dec::mul($marketing['amount'], $marketingFactor));

        return [
            'net_sales' => Dec::round($sales),
            'royalty_gross' => $royalty['amount'],
            'startup_month' => $month,
            'startup_factor' => bcadd($factor, '0', 6),
            'royalty_after_startup' => $royaltyAfterStartup,
            'minimum_guarantee_applied' => $guarantee,
            'minimum_adjustment' => Dec::round(Dec::sub($charged, $royaltyAfterStartup)),
            'royalty_charged' => Dec::round($charged),
            'marketing_fund' => $marketingCharged,
            'breakdown' => ['royalty_slices' => $royalty['slices'], 'marketing_slices' => $marketing['slices'], 'marketing_gross' => $marketing['amount']],
        ];
    }

    /**
     * 1-based month of the store life the period falls in (1 = the opening month); null before the opening date or when unknown.
     */
    public static function startupMonth(?CarbonImmutable $openedOn, string $period): ?int
    {
        if ($openedOn === null) {
            return null;
        }

        $periodStart = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
        $openingMonth = $openedOn->startOfMonth();

        if ($periodStart->lessThan($openingMonth)) {
            return null;
        }

        return (int) $openingMonth->diffInMonths($periodStart) + 1;
    }

    /**
     * @param  array<string, mixed>  $formula
     */
    public static function startupFactor(array $formula, ?int $month): string
    {
        $months = (int) $formula['startup_months'];
        $mode = StartupMode::tryFrom((string) $formula['startup_mode']) ?? StartupMode::None;

        if ($month === null || $months <= 0 || $month > $months || $mode === StartupMode::None) {
            return Dec::of(1);
        }

        return match ($mode) {
            StartupMode::FullExemption => Dec::of(0),
            StartupMode::ReducedRate => Dec::sub(1, Dec::div($formula['startup_reduction_percent'], 100)),
            default => Dec::div(
                Dec::add($formula['ramp_start_percent'], Dec::div(Dec::mul(Dec::sub(100, $formula['ramp_start_percent']), $month - 1), $months)),
                100,
            ),
        };
    }
}
