<?php

declare(strict_types=1);

namespace App\Services\Invoicing\Tax;

use App\Support\Money;

/**
 * The fiscal rules shared by the document calculator (ItalianFiscalCalculator) and the emission service
 * (InvoiceFiscalService), in integer cents, so the two paths cannot drift apart.
 */
final class FiscalRules
{
    /**
     * Natura codes whose amounts count towards the stamp duty threshold: exempt, non-taxable and out-of-scope (N1-N4).
     *
     * @return list<string>
     */
    public static function stampDutyNaturaCodes(): array
    {
        /** @var list<string> $codes */
        $codes = array_values((array) config('italian_tax.stamp_duty.natura_codes', []));

        return $codes;
    }

    public static function isStampDutyNatura(?string $natura): bool
    {
        return $natura !== null && in_array($natura, self::stampDutyNaturaCodes(), true);
    }

    /** Stamp duty is due strictly above the threshold (exactly EUR 77.47 is not subject). */
    public static function stampDutyDue(int $exemptBaseCents): bool
    {
        return $exemptBaseCents > Money::toCents((string) config('fiscal_it.stamp_duty.threshold', '77.47'));
    }

    public static function stampDutyAmountCents(): int
    {
        return Money::toCents((string) config('fiscal_it.stamp_duty.amount', '2.00'));
    }

    /** Commissions still subject to the contribution below the annual ceiling. */
    public static function enasarcoTaxable(int $commissions, int $ceilingCents, int $alreadySubjectCents): int
    {
        return max(0, min($commissions, max(0, $ceilingCents - $alreadySubjectCents)));
    }

    /**
     * Contribution on the taxable commissions. The annual minimum is guaranteed on the first contribution-bearing
     * invoice: the year's contribution (already withheld plus this one) is raised up to the minimum, never beyond it.
     */
    public static function enasarcoContribution(int $taxable, string $ratePercent, int $minimumCents = 0, int $alreadyContributedCents = 0): int
    {
        $amount = Money::percentOf($taxable, $ratePercent);

        if ($taxable <= 0 || $minimumCents <= 0) {
            return $amount;
        }

        return max($amount, $minimumCents - $alreadyContributedCents);
    }
}
