<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Services;

use Modules\Payroll\Calculation\Dec;

/**
 * TFR and monthly accruals under art. 2120 of the Civil Code. All amounts are numeric strings rounded to cents.
 */
final class ItalianTfrCalculator
{
    /** The quota of the annual TFR divisor: the yearly pay divided by 13.5. */
    public const string Divisor = '13.5';

    /** The INPS 0.50% supplementary contribution that is deducted from the TFR quota. */
    public const string InpsRate = '0.005';

    /** Fixed 1.5% of the annual revaluation, plus 75% of the ISTAT FOI inflation. */
    public const string FixedRevaluation = '0.015';

    public const string FoiShare = '0.75';

    /** Substitute tax on the revaluation (d.lgs. 47/2000). */
    public const string SubstituteTaxRate = '0.17';

    /**
     * Monthly TFR provision: gross / 13.5 minus the 0.50% INPS contribution on the same gross.
     */
    public function monthlyProvision(string $grossWages): string
    {
        $quota = Dec::div($grossWages, self::Divisor);

        return Dec::round(Dec::sub($quota, Dec::mul($grossWages, self::InpsRate)));
    }

    /**
     * Annual revaluation rate as a fraction: 1.5% + 0.75 x FOI (the FOI index is a percent change, e.g. '2.4').
     */
    public function revaluationRate(string $foiPercent): string
    {
        $foi = Dec::div($foiPercent, '100');

        return Dec::add(self::FixedRevaluation, Dec::mul(self::FoiShare, $foi));
    }

    /**
     * Gross revaluation of the TFR fund at the start of the year.
     */
    public function revaluation(string $fundBalance, string $foiPercent): string
    {
        return Dec::round(Dec::mul($fundBalance, $this->revaluationRate($foiPercent)));
    }

    public function substituteTax(string $revaluation): string
    {
        return Dec::round(Dec::mul($revaluation, self::SubstituteTaxRate));
    }

    /**
     * @return array{gross: string, tax: string, net: string, rate: string}
     */
    public function netRevaluation(string $fundBalance, string $foiPercent): array
    {
        $gross = $this->revaluation($fundBalance, $foiPercent);
        $tax = $this->substituteTax($gross);

        return ['gross' => $gross, 'tax' => $tax, 'net' => Dec::round(Dec::sub($gross, $tax)), 'rate' => $this->revaluationRate($foiPercent)];
    }

    /**
     * The monthly accrual of the 13th or 14th month pay: 1/12 of the monthly pay for each month worked in the year.
     * A fraction of the month (monthsWorked) is accepted for hirings and terminations in mid-year.
     */
    public function additionalMonthlyAccrual(string $monthlyPay, string $monthsWorked = '1'): string
    {
        $fraction = Dec::div($monthsWorked, '12');

        return Dec::round(Dec::mul($monthlyPay, $fraction));
    }
}
