<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Modules\AccountsPayable\DTOs\SkontoResult;
use Modules\AccountsPayable\DTOs\SkontoTerms;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The cash-discount engine (Skonto / sconto cassa / escompte). Pure: it only needs the terms and the dates.
 *
 * The deadline is the document date plus the discount days (`30 gg data fattura, 2% entro 10 gg` gives the invoice
 * date + 10). The discount is a percentage of the open balance, rounded to the cent, and only an invoice that has not
 * been paid yet can earn it: a partial payment, a credit note or an expired deadline leave the standard amount.
 */
final class SkontoCalculator
{
    public function calculate(
        ?SkontoTerms $terms,
        CarbonInterface $documentDate,
        string $openAmount,
        bool $isCreditNote,
        bool $hasPayments,
        CarbonInterface $asOf,
    ): SkontoResult {
        $standard = Decimal::round2($openAmount);

        if ($terms === null || ! $terms->isUsable()) {
            return new SkontoResult(false, null, null, null, '0.00', $standard, $standard, null, null);
        }

        $deadline = CarbonImmutable::parse($documentDate)->startOfDay()->addDays($terms->days);
        $remaining = (int) round(CarbonImmutable::parse($asOf)->startOfDay()->diffInDays($deadline, false));
        $discount = Decimal::round2(Decimal::div(Decimal::mul($standard, $terms->percentage), '100'));
        $eligible = ! $isCreditNote && ! $hasPayments && $remaining >= 0 && Decimal::isPositive($standard) && Decimal::isPositive($discount);
        $savings = $eligible ? $discount : '0.00';

        return new SkontoResult(
            eligible: $eligible,
            percentage: Decimal::round2($terms->percentage),
            deadlineDate: $deadline->toDateString(),
            daysRemaining: $remaining,
            savingsAmount: $savings,
            netPayableWithSkonto: Decimal::round2(Decimal::sub($standard, $savings)),
            standardAmount: $standard,
            termsSource: $terms->source,
            netDays: $terms->netDays,
        );
    }
}
