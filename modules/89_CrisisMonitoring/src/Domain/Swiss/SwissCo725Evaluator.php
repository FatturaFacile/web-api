<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Domain\Swiss;

use Modules\CrisisMonitoring\Domain\Enums\TrafficLight;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The three guardrails of the Swiss Code of Obligations (revised corporate law):
 *
 * - Art. 725 CO threat of insolvency: the cash, with the committed credit lines, must not turn negative in the next twelve months
 *   (red within six months, yellow within twelve);
 * - Art. 725a CO capital loss: net assets no longer cover half of the share capital plus the statutory reserves;
 * - Art. 725b CO over-indebtedness: the liabilities exceed the assets both at liquidation values and, when the continuation is
 *   probable, at going-concern values, after the claims subordinated by a Rangrücktritt.
 */
final readonly class SwissCo725Evaluator
{
    /** Net assets under this share of the protected capital turn the Art. 725a light yellow before the loss of half. */
    private const string CapitalWatch = '0.75';

    /**
     * @param  list<array<string, mixed>>  $months  the monthly projection rows (see DscrCalculator::project)
     * @return array<string, mixed>
     */
    public function liquidity(array $months, string $openingCash, string $creditLines): array
    {
        $min = null;
        $minMonth = null;
        $firstNegative = null;
        $runway = null;
        $inflows = ['m6' => '0', 'm12' => '0'];
        $outflows = ['m6' => '0', 'm12' => '0'];
        $plan = [];

        foreach ($months as $index => $month) {
            $outflow = Decimal::add(Decimal::add($month['operating_outflows'], $month['debt_service']), $month['capex']);
            $plan[] = [
                'month' => $month['month'],
                'inflows' => $month['operating_inflows'],
                'outflows' => Decimal::round2($outflow),
                'net' => Decimal::round2(Decimal::sub($month['operating_inflows'], $outflow)),
                'closing_cash' => $month['closing_cash'],
            ];
            $withCredit = $month['cumulative_cash_with_credit'];

            if ($min === null || Decimal::compare($withCredit, $min) < 0) {
                $min = $withCredit;
                $minMonth = $month['month'];
            }

            if ($firstNegative === null && Decimal::isNegative($withCredit)) {
                $firstNegative = $month['month'];
                $runway = $index;
            }

            foreach (['m6' => 6, 'm12' => 12] as $key => $limit) {
                if ($index < $limit) {
                    $inflows[$key] = Decimal::add($inflows[$key], $month['operating_inflows']);
                    $outflows[$key] = Decimal::add($outflows[$key], $outflow);
                }
            }
        }

        $status = match (true) {
            $runway !== null && $runway < 6 => TrafficLight::Red,
            $runway !== null => TrafficLight::Yellow,
            default => TrafficLight::Green,
        };
        $available = Decimal::add($openingCash, $creditLines);
        $coverage = static fn (string $key): ?string => Decimal::isPositive($outflows[$key])
            ? Decimal::round2(Decimal::div(Decimal::add($available, $inflows[$key]), $outflows[$key]))
            : null;

        return [
            'status' => $status->value,
            'opening_cash' => Decimal::round2($openingCash),
            'committed_credit_lines' => Decimal::round2($creditLines),
            'min_cumulative_cash' => $min === null ? Decimal::round2('0') : Decimal::round2($min),
            'min_month' => $minMonth,
            'first_negative_month' => $firstNegative,
            'runway_months' => $runway,
            'coverage_6m' => $coverage('m6'),
            'coverage_12m' => $coverage('m12'),
            'plan' => $plan,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function capitalLoss(string $netAssets, ?string $shareCapital, ?string $statutoryReserves): array
    {
        $capital = $shareCapital ?? '0';
        $protected = Decimal::add($capital, $statutoryReserves ?? '0');
        $configured = Decimal::isPositive($capital);
        $required = Decimal::mul($protected, '0.5');
        $coverage = $configured ? Decimal::div($netAssets, $protected) : null;
        $loss = $configured && Decimal::compare($netAssets, $required) < 0;
        $status = match (true) {
            ! $configured => TrafficLight::Green,
            $loss => TrafficLight::Red,
            Decimal::compare($coverage ?? '1', self::CapitalWatch) < 0 => TrafficLight::Yellow,
            default => TrafficLight::Green,
        };
        $meter = $coverage === null ? 0 : (int) max(0, min(100, round((float) Decimal::mul($coverage, '100'))));

        return [
            'status' => $status->value,
            'configured' => $configured,
            'net_assets' => Decimal::round2($netAssets),
            'share_capital' => Decimal::round2($capital),
            'statutory_reserves' => Decimal::round2($statutoryReserves ?? '0'),
            'protected_capital' => Decimal::round2($protected),
            'required_net_assets' => Decimal::round2($required),
            'coverage_ratio' => $coverage === null ? null : Decimal::round2($coverage),
            'meter_percent' => $meter,
            'capital_loss' => $loss,
        ];
    }

    /**
     * @param  string  $subordinatedCovered  the claims covered by the active Rangrücktritt agreements
     * @return array<string, mixed>
     */
    public function overIndebtedness(string $assetsGoingConcern, string $assetsLiquidation, string $liabilities, bool $goingConcernProbable, string $subordinatedCovered): array
    {
        $gcShortfall = Decimal::sub($liabilities, $assetsGoingConcern);
        $liqShortfall = Decimal::sub($liabilities, $assetsLiquidation);
        $gcOver = Decimal::isPositive($gcShortfall);
        $liqOver = Decimal::isPositive($liqShortfall);

        // The cure is the cheaper way out: cover the liquidation values or, when the continuation is probable, the going-concern values.
        $overIndebted = $liqOver && (! $goingConcernProbable || $gcOver);
        $basis = $goingConcernProbable && Decimal::compare($gcShortfall, $liqShortfall) < 0 ? 'going_concern' : 'liquidation';
        $shortfall = $overIndebted ? ($basis === 'going_concern' ? $gcShortfall : $liqShortfall) : '0';
        $remaining = Decimal::isPositive(Decimal::sub($shortfall, $subordinatedCovered)) ? Decimal::sub($shortfall, $subordinatedCovered) : '0';
        $cured = $overIndebted && ! Decimal::isPositive($remaining);
        $status = match (true) {
            Decimal::isPositive($remaining) => TrafficLight::Red,
            $cured => TrafficLight::Yellow,
            default => TrafficLight::Green,
        };

        return [
            'status' => $status->value,
            'basis' => $basis,
            'going_concern' => ['assets' => Decimal::round2($assetsGoingConcern), 'liabilities' => Decimal::round2($liabilities), 'net' => Decimal::round2(Decimal::sub($assetsGoingConcern, $liabilities)), 'overindebted' => $gcOver],
            'liquidation' => ['assets' => Decimal::round2($assetsLiquidation), 'liabilities' => Decimal::round2($liabilities), 'net' => Decimal::round2(Decimal::sub($assetsLiquidation, $liabilities)), 'overindebted' => $liqOver],
            'shortfall' => Decimal::round2($shortfall),
            'subordinated_covered' => Decimal::round2($subordinatedCovered),
            'remaining_shortfall' => Decimal::round2($remaining),
            'overindebted' => Decimal::isPositive($remaining),
            'court_notification_required' => Decimal::isPositive($remaining),
        ];
    }
}
