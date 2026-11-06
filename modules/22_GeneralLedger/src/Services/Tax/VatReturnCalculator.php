<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Computes a periodic VAT return: Swiss MWST statement with the effective method (turnover, tax by rate, input tax) or
 * Italian periodic liquidation (VAT on sales without split payment and reverse charge, deductible VAT, previous credit,
 * 1% interest for quarterly taxpayers, minimum payment of EUR 25.82).
 *
 * Port of FatturaFacile.Application.Taxation.VatReturnCalculator. The only additions are optional and neutral at their
 * defaults: `VatSaleItem::$tax` (tax as posted in the ledger) and `$previousDebit` (debt below the minimum carried over).
 */
final class VatReturnCalculator
{
    /** The Italian minimum amount to pay; smaller debts are carried to the next period. */
    public const string ITALIAN_MINIMUM_PAYMENT = '25.82';

    /** A period longer than this many days is a quarter. */
    private const int MONTHLY_PERIOD_MAX_DAYS = 40;

    /**
     * @param  string  $country  CH or IT
     * @param  list<VatSaleDocument>  $sales  issued customer documents of the period
     * @param  list<VatPurchaseDocument>  $purchases  posted supplier documents registered in the period
     * @param  string  $previousCredit  credit carried over from the previous return
     * @param  string  $previousDebit  debt below the minimum payment carried over from the previous return
     */
    public static function compute(
        string $country,
        DateTimeInterface $from,
        DateTimeInterface $to,
        array $sales,
        array $purchases,
        string $previousCredit = '0',
        string $previousDebit = '0',
    ): VatReturnResult {
        $italian = strcasecmp($country, 'IT') === 0;
        $byRate = [];
        $turnover = '0';

        foreach ($sales as $invoice) {
            $sign = stripos($invoice->invoiceType, 'credit') !== false ? '-1' : '1';
            $turnover = Decimal::add($turnover, Decimal::mul($sign, $invoice->totalNet));
            $vatCollected = ! ($italian && ($invoice->splitPayment || $invoice->reverseCharge));

            foreach ($invoice->items as $item) {
                $hasPostedTax = $item->tax !== null && ! Decimal::isZero($item->tax);

                if (! Decimal::isPositive($item->taxRate) && ! $hasPostedTax) {
                    continue;
                }

                $tax = $vatCollected ? self::taxOf($item) : '0';
                $key = Decimal::formatRate($item->taxRate);
                $current = $byRate[$key] ?? ['taxable' => '0', 'tax' => '0'];
                $byRate[$key] = [
                    'taxable' => Decimal::add($current['taxable'], Decimal::mul($sign, $item->lineTotal)),
                    'tax' => Decimal::add($current['tax'], Decimal::mul($sign, $tax)),
                ];
            }
        }

        uksort($byRate, static fn (int|string $left, int|string $right): int => Decimal::compare((string) $right, (string) $left));

        $rates = [];
        $details = [];
        $output = '0';
        $taxableTurnover = '0';

        foreach ($byRate as $rate => $values) {
            $rates[] = new VatRateTotal((string) $rate, Decimal::round2($values['taxable']), Decimal::round2($values['tax']));
            $details[] = sprintf('%s%%: imponibile %s, imposta %s', $rate, Decimal::formatGrouped($values['taxable']), Decimal::formatGrouped($values['tax']));
            $output = Decimal::add($output, $values['tax']);
            $taxableTurnover = Decimal::add($taxableTurnover, $values['taxable']);
        }

        $input = '0';

        foreach ($purchases as $purchase) {
            $input = Decimal::add($input, $purchase->deductibleVat);
        }

        $details[] = sprintf('IVA precedente detraibile: %s (%d fatture fornitori)', Decimal::formatGrouped($input), count($purchases));

        $balance = Decimal::add(Decimal::sub(Decimal::sub($output, $input), $previousCredit), $previousDebit);
        $interest = '0';

        if ($italian && Decimal::isPositive($balance) && self::isLongerThanOneMonth($from, $to)) {
            $interest = Decimal::round2(Decimal::mul($balance, '0.01'));
            $details[] = sprintf('Interessi trimestrali 1%%: %s', Decimal::formatGrouped($interest));
        }

        $payable = Decimal::add($balance, $interest);
        $belowMinimum = $italian
            && Decimal::isPositive($payable)
            && Decimal::compare($payable, self::ITALIAN_MINIMUM_PAYMENT) < 0;

        if ($belowMinimum) {
            $details[] = 'Importo inferiore a 25.82 EUR: non si versa, si riporta al periodo successivo.';
        }

        return new VatReturnResult(
            country: $italian ? 'IT' : 'CH',
            periodFrom: $from->format('Y-m-d'),
            periodTo: $to->format('Y-m-d'),
            turnover: Decimal::round2($turnover),
            taxableTurnover: Decimal::round2($taxableTurnover),
            outputVat: Decimal::round2($output),
            inputVat: Decimal::round2($input),
            previousCredit: Decimal::round2($previousCredit),
            previousDebit: Decimal::round2($previousDebit),
            interest: Decimal::round2($interest),
            payable: Decimal::round2($payable),
            belowMinimumPayment: $belowMinimum,
            rates: $rates,
            details: implode(PHP_EOL, $details),
        );
    }

    private static function taxOf(VatSaleItem $item): string
    {
        return $item->tax ?? Decimal::round2(Decimal::div(Decimal::mul($item->lineTotal, $item->taxRate), '100'));
    }

    private static function isLongerThanOneMonth(DateTimeInterface $from, DateTimeInterface $to): bool
    {
        $firstDay = new DateTimeImmutable($from->format('Y-m-d'));
        $lastDay = new DateTimeImmutable($to->format('Y-m-d'));

        return (int) $firstDay->diff($lastDay)->days > self::MONTHLY_PERIOD_MAX_DAYS;
    }
}
