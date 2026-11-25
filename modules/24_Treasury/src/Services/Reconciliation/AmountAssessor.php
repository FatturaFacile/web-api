<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\Reconciliation;

use App\Models\BankStatementLine;
use Carbon\CarbonImmutable;

/**
 * Decides whether and how a statement line can settle a document balance:
 *
 * - within the tolerance: the document is settled (`exact`);
 * - a shortfall up to the maximum bank fee on an incoming line without a declared fee: the bank charged commissions
 *   (`fee_tolerance`, the shortfall becomes a bank-charge posting);
 * - a document in another currency: the line is converted at the rate of the booking date and the difference up to the
 *   FX tolerance is an exchange difference (`fx`);
 * - any other shortfall: a `partial` settlement, when allowed;
 * - an excess over the balance is never proposed.
 */
final readonly class AmountAssessor
{
    public function __construct(private CurrencyConverter $converter) {}

    /**
     * @param  string  $due  balance of the document in its own currency
     */
    public function assess(BankStatementLine $line, string $documentCurrency, string $due, SmartSettings $settings): ?Assessment
    {
        $due = bcadd($due, '0', 2);

        if (bccomp($due, '0', 2) <= 0) {
            return null;
        }

        $lineCurrency = strtoupper((string) $line->currency);
        $documentCurrency = strtoupper($documentCurrency);

        return $lineCurrency === $documentCurrency
            ? $this->sameCurrency($line, $due, $settings)
            : $this->foreign($line, $lineCurrency, $documentCurrency, $due, $settings);
    }

    private function sameCurrency(BankStatementLine $line, string $due, SmartSettings $settings): ?Assessment
    {
        $credit = $line->isCredit();
        $fee = bcadd((string) $line->fee_amount, '0', 2);
        $gross = bcadd((string) $line->amount, $credit ? $fee : '0', 2);
        $difference = bcsub($due, $gross, 2);
        $tolerance = number_format($settings->base->tolerance, 2, '.', '');

        if (bccomp($this->abs($difference), $tolerance, 2) <= 0) {
            return new Assessment(Assessment::EXACT, $due, $credit ? $fee : '0.00', '0.00', '0.00');
        }

        if (bccomp($difference, '0', 2) < 0) {
            return null;
        }

        if ($credit && bccomp($fee, '0', 2) === 0 && bccomp($difference, number_format($settings->base->maxFee, 2, '.', ''), 2) <= 0) {
            return new Assessment(Assessment::FEE_TOLERANCE, $due, $difference, '0.00', '0.00');
        }

        return $settings->base->allowPartial
            ? new Assessment(Assessment::PARTIAL, $gross, $credit ? $fee : '0.00', '0.00', $difference)
            : null;
    }

    private function foreign(BankStatementLine $line, string $lineCurrency, string $documentCurrency, string $due, SmartSettings $settings): ?Assessment
    {
        $rate = $this->converter->rate((string) $line->tenant_id, $lineCurrency, $documentCurrency, CarbonImmutable::parse($line->booking_date)->toDateString());

        if ($rate === null) {
            return null;
        }

        $credit = $line->isCredit();
        $net = CurrencyConverter::round2(bcmul((string) $line->amount, $rate, 10));
        $fee = CurrencyConverter::round2(bcmul((string) $line->fee_amount, $rate, 10));
        // What the bank really moved, in the currency of the document.
        $moved = $credit ? bcadd($net, $fee, 2) : $net;
        $gain = $credit ? bcsub($moved, $due, 2) : bcsub($due, $moved, 2);
        $percent = bcmul(bcdiv($this->abs($gain), $due, 10), '100', 6);

        if (bccomp($percent, number_format($settings->fxTolerancePercent, 6, '.', ''), 6) > 0) {
            return null;
        }

        return new Assessment(Assessment::FX, $due, $fee, $gain, '0.00', $rate);
    }

    private function abs(string $value): string
    {
        return ltrim($value, '-') === '' ? '0' : ltrim($value, '-');
    }
}
