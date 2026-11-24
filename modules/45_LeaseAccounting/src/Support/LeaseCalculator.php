<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Support;

use Carbon\CarbonImmutable;

/**
 * The arithmetic of lease accounting: payment plan, present value with the effective-rate method, the amortisation of
 * the lease liability and the straight-line depreciation of the right-of-use asset. The schedule has one row per
 * month; interest accrues monthly at the incremental borrowing rate divided by twelve. Everything is bcmath on decimal
 * strings, rounded to two decimals per row; the rounding residual of the whole schedule is absorbed by the interest of
 * the period of the last payment so that the liability closes at exactly zero.
 */
final class LeaseCalculator
{
    /**
     * Interest rate of one month for an annual rate in percent (nominal rate, monthly compounding).
     */
    public static function monthlyRate(string $annualRatePercent): string
    {
        return bcdiv($annualRatePercent, '1200', LeaseMoney::RateScale);
    }

    public static function periodStart(CarbonImmutable $commencement, int $period): CarbonImmutable
    {
        return $commencement->addMonthsNoOverflow($period - 1);
    }

    public static function periodEnd(CarbonImmutable $commencement, int $period): CarbonImmutable
    {
        return $commencement->addMonthsNoOverflow($period)->subDay();
    }

    /**
     * The payment in force at a period, given the payment at the first period of the version and the escalation that
     * steps up on every twelfth month.
     */
    public static function paymentInForceAt(LeaseTerms $terms, int $firstPeriod, int $period): string
    {
        $steps = intdiv($period - 1, 12) - intdiv($firstPeriod - 1, 12);

        if ($steps <= 0 || LeaseMoney::isZero($terms->escalationRatePercent)) {
            return LeaseMoney::round($terms->paymentAmount);
        }

        $factor = bcpow(bcadd('1', bcdiv($terms->escalationRatePercent, '100', LeaseMoney::RateScale), LeaseMoney::RateScale), (string) $steps, LeaseMoney::RateScale);

        return LeaseMoney::round(LeaseMoney::mul($terms->paymentAmount, $factor));
    }

    /**
     * Payments of each period from the first to the last, split in what falls due at the start of the period and what
     * falls due at its end.
     *
     * @return array<int, array{advance: string, arrears: string}>
     */
    public static function paymentPlan(LeaseTerms $terms, int $firstPeriod, int $lastPeriod): array
    {
        $plan = [];

        for ($period = $firstPeriod; $period <= $lastPeriod; $period++) {
            $amount = self::paymentInForceAt($terms, $firstPeriod, $period);
            $advance = $terms->timing === 'advance' && ($period - 1) % $terms->frequencyMonths === 0 ? $amount : '0.00';
            $arrears = $terms->timing === 'arrears' && $period % $terms->frequencyMonths === 0 ? $amount : '0.00';

            if ($period === $lastPeriod && $terms->purchaseOptionPrice !== null) {
                $arrears = LeaseMoney::round(LeaseMoney::add($arrears, $terms->purchaseOptionPrice));
            }

            $plan[$period] = ['advance' => $advance, 'arrears' => $arrears];
        }

        return $plan;
    }

    /**
     * Present value, at the first period of the plan, of every payment of the plan discounted at the monthly rate.
     *
     * @param  array<int, array{advance: string, arrears: string}>  $plan
     */
    public static function presentValue(array $plan, string $annualRatePercent): string
    {
        $monthly = self::monthlyRate($annualRatePercent);
        $growth = bcadd('1', $monthly, LeaseMoney::RateScale);
        $factorAtStart = '1';
        $total = '0';

        foreach ($plan as $payments) {
            $factorAtEnd = bcdiv($factorAtStart, $growth, LeaseMoney::RateScale);
            $total = LeaseMoney::add($total, LeaseMoney::add(LeaseMoney::mul($payments['advance'], $factorAtStart), LeaseMoney::mul($payments['arrears'], $factorAtEnd)));
            $factorAtStart = $factorAtEnd;
        }

        return LeaseMoney::round($total);
    }

    /**
     * Sum of the payments of the plan, undiscounted.
     *
     * @param  array<int, array{advance: string, arrears: string}>  $plan
     */
    public static function totalPayments(array $plan): string
    {
        $total = '0';

        foreach ($plan as $payments) {
            $total = LeaseMoney::add($total, LeaseMoney::add($payments['advance'], $payments['arrears']));
        }

        return LeaseMoney::round($total);
    }

    /**
     * Number of months over which the right-of-use asset is depreciated: the remaining lease term, or the remaining
     * useful life of the asset when the purchase option is reasonably certain to be exercised (IFRS 16.32).
     */
    public static function depreciationMonths(int $firstPeriod, int $lastPeriod, ?int $usefulLifeMonths, bool $ownershipExpected): int
    {
        $leaseMonths = $lastPeriod - $firstPeriod + 1;

        if (! $ownershipExpected || $usefulLifeMonths === null) {
            return $leaseMonths;
        }

        return max($leaseMonths, $usefulLifeMonths - ($firstPeriod - 1));
    }

    /**
     * The monthly rows of the liability and of the asset.
     *
     * @param  array<int, array{advance: string, arrears: string}>  $plan
     * @return list<array{period_number: int, period_start: string, period_end: string, opening_liability: string, payment: string, interest: string, principal: string, closing_liability: string, rou_opening: string, depreciation: string, rou_closing: string}>
     */
    public static function buildLines(array $plan, CarbonImmutable $commencement, string $annualRatePercent, string $openingLiability, string $openingRou, int $depreciationMonths): array
    {
        $monthly = self::monthlyRate($annualRatePercent);
        $periods = array_keys($plan);
        $firstPeriod = $periods[0];
        $lastPaymentPeriod = $firstPeriod;

        foreach ($plan as $period => $payments) {
            if (LeaseMoney::isPositive($payments['advance']) || LeaseMoney::isPositive($payments['arrears'])) {
                $lastPaymentPeriod = $period;
            }
        }

        $monthlyDepreciation = $depreciationMonths > 0 ? LeaseMoney::round(bcdiv($openingRou, (string) $depreciationMonths, LeaseMoney::RateScale)) : '0.00';
        $liability = $openingLiability;
        $rou = $openingRou;
        $lines = [];

        foreach ($plan as $period => $payments) {
            $base = LeaseMoney::sub($liability, $payments['advance']);
            $interest = LeaseMoney::round(LeaseMoney::mul($base, $monthly));
            $closing = LeaseMoney::sub(LeaseMoney::add($base, $interest), $payments['arrears']);

            if ($period === $lastPaymentPeriod) {
                $interest = LeaseMoney::round(LeaseMoney::sub($interest, $closing));
                $closing = '0.00';
            }

            $payment = LeaseMoney::round(LeaseMoney::add($payments['advance'], $payments['arrears']));
            $position = $period - $firstPeriod + 1;
            $depreciation = match (true) {
                $position < $depreciationMonths => $monthlyDepreciation,
                $position === $depreciationMonths => $rou,
                default => '0.00',
            };

            if (LeaseMoney::compare($depreciation, $rou) > 0) {
                $depreciation = $rou;
            }

            $rouClosing = LeaseMoney::round(LeaseMoney::sub($rou, $depreciation));
            $lines[] = [
                'period_number' => $period,
                'period_start' => self::periodStart($commencement, $period)->toDateString(),
                'period_end' => self::periodEnd($commencement, $period)->toDateString(),
                'opening_liability' => LeaseMoney::round($liability),
                'payment' => $payment,
                'interest' => LeaseMoney::round($interest),
                'principal' => LeaseMoney::round(LeaseMoney::sub($payment, $interest)),
                'closing_liability' => LeaseMoney::round($closing),
                'rou_opening' => LeaseMoney::round($rou),
                'depreciation' => LeaseMoney::round($depreciation),
                'rou_closing' => $rouClosing,
            ];

            $liability = LeaseMoney::round($closing);
            $rou = $rouClosing;
        }

        return $lines;
    }
}
