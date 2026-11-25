<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Strategies;

use Modules\ExpenseManagement\Domain\ValueObjects\PerDiemFacts;
use Modules\ExpenseManagement\Domain\ValueObjects\PerDiemResult;
use Modules\ExpenseManagement\Support\Decimal;

/**
 * Arithmetic shared by the jurisdictions: the daily limit is reduced by a third for each of meals and lodging provided or
 * reimbursed analytically (mixed regime), then the gross allowance is split into tax-free and taxable excess.
 */
final class PerDiemReductions
{
    public static function apply(string $dailyLimit, bool $meals, bool $lodging): string
    {
        $limit = $dailyLimit;
        $third = Decimal::div($dailyLimit, '3', 2);

        foreach ([$meals, $lodging] as $reimbursed) {
            if ($reimbursed) {
                $limit = Decimal::sub($limit, $third);
            }
        }

        return Decimal::cmp($limit, '0') < 0 ? '0.00' : $limit;
    }

    /**
     * @param  string  $defaultRate  the daily rate used when the caller gave none
     */
    public static function result(PerDiemFacts $facts, string $baseLimit, string $effectiveLimit, string $defaultRate): PerDiemResult
    {
        $rate = $facts->ratePerDay !== null ? Decimal::normalize($facts->ratePerDay) : $defaultRate;
        $gross = Decimal::mul($rate, $facts->daysCount);
        $limitTotal = Decimal::mul($effectiveLimit, $facts->daysCount);
        $taxFree = Decimal::min($gross, $limitTotal);
        $excess = Decimal::sub($gross, $taxFree);
        $reductions = Decimal::mul(Decimal::sub($baseLimit, $effectiveLimit), $facts->daysCount);

        $warnings = [];

        if (Decimal::isPositive($excess)) {
            $warnings[] = ['code' => 'taxable_per_diem_excess', 'params' => ['excess' => $excess, 'daily_limit' => $effectiveLimit]];
        }

        return new PerDiemResult($rate, $gross, $effectiveLimit, $taxFree, $excess, $reductions, $warnings);
    }
}
