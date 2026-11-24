<?php

declare(strict_types=1);

namespace Modules\Projects\Support;

use InvalidArgumentException;

/**
 * Arithmetic of a SAL progress certificate (Mod 081), on exact decimals.
 *
 *  cumulative gross earned value = contract value x cumulative completion %
 *  current period gross SAL      = cumulative gross earned value - cumulative gross of the previous certificates
 *  advance deduction             = advance total x (current gross / contract value), the whole remaining advance on the
 *                                  final certificate, never more than the advance not yet recouped
 *  guarantee retention withheld  = current gross x retention %
 *  invoice amount                = current gross - advance deduction (VAT base of the Akontorechnung / fattura SAL)
 *  net billable amount           = invoice amount - retention withheld (what the customer pays now, VAT excluded)
 *
 * The retention is a withholding on payment, not a reduction of the invoiced consideration, so it does not touch the
 * VAT base; it is released later against the retention ledger.
 */
final class SalCalculator
{
    /**
     * @return array{cumulative_gross_earned_value: string, current_period_gross_sal: string, advance_deduction_amount: string, guarantee_retention_withheld: string, invoice_amount: string, net_billable_amount: string, is_final: bool}
     *
     * @throws InvalidArgumentException when the contract value is not positive or the progress does not advance within 0-100
     */
    public static function compute(
        string $contractValue,
        string $cumulativeCompletionPercent,
        string $previousCompletionPercent,
        string $previousCumulativeGross,
        string $advanceTotal,
        string $advanceRecouped,
        string $retentionPercent,
    ): array {
        if (! Money::isPositive($contractValue)) {
            throw new InvalidArgumentException('The contract value must be positive.');
        }

        if (Money::compare($cumulativeCompletionPercent, 100) > 0 || Money::compare($cumulativeCompletionPercent, $previousCompletionPercent) <= 0) {
            throw new InvalidArgumentException('The cumulative completion must exceed the previous one and not exceed 100%.');
        }

        $final = Money::compare($cumulativeCompletionPercent, 100) === 0;
        $cumulativeGross = Money::amount(Money::mul($contractValue, Money::div($cumulativeCompletionPercent, 100)));
        $currentGross = Money::amount(Money::sub($cumulativeGross, $previousCumulativeGross));

        $remainingAdvance = Money::max(Money::sub($advanceTotal, $advanceRecouped), 0);
        $proportional = Money::mul($advanceTotal, Money::div($currentGross, $contractValue) ?? '0');
        $advanceDeduction = Money::amount(Money::min($final ? $remainingAdvance : $proportional, Money::min($remainingAdvance, $currentGross)));

        $retention = Money::amount(Money::mul($currentGross, Money::div($retentionPercent, 100)));
        $invoiceAmount = Money::amount(Money::sub($currentGross, $advanceDeduction));

        return [
            'cumulative_gross_earned_value' => $cumulativeGross,
            'current_period_gross_sal' => $currentGross,
            'advance_deduction_amount' => $advanceDeduction,
            'guarantee_retention_withheld' => $retention,
            'invoice_amount' => $invoiceAmount,
            'net_billable_amount' => Money::amount(Money::sub($invoiceAmount, $retention)),
            'is_final' => $final,
        ];
    }
}
