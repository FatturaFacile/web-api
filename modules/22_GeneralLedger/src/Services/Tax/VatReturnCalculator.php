<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Computes a periodic VAT return: Swiss MWST statement with the effective method (turnover, tax by rate, input tax) or
 * Italian periodic liquidation (VAT on sales without split payment and reverse charge, deductible VAT, previous credit,
 * 1% interest for quarterly taxpayers, minimum payment of EUR 100: it was EUR 25.82 until the end of 2023).
 *
 * Port of FatturaFacile.Application.Taxation.VatReturnCalculator. The only additions are optional and neutral at their
 * defaults: `VatSaleItem::$tax` (tax as posted in the ledger) and `$previousDebit` (debt below the minimum carried over).
 */
final class VatReturnCalculator
{
    /**
     * The Italian minimum amount to pay: a periodic debt that does not exceed it is carried to the next period. Raised from
     * EUR 25.82 to EUR 100 for the periods from January 2024 by the "decreto Adempimenti" (art. 1 c. 4 D.P.R. 100/1998 and
     * art. 7 c. 1 lett. a D.P.R. 542/1999 as amended), see Agenzia delle Entrate circolare 9/E of 2 May 2024 and the
     * instructions of the LIPE communication 2024 (rigo VP7, "debito periodo precedente non superiore a 100 euro").
     */
    public const string ITALIAN_MINIMUM_PAYMENT = '100.00';

    /** A period longer than this many days is a quarter. */
    private const int MONTHLY_PERIOD_MAX_DAYS = 40;

    /**
     * @param  string  $country  CH or IT
     * @param  list<VatSaleDocument>  $sales  issued customer documents of the period
     * @param  list<VatPurchaseDocument>  $purchases  posted supplier documents registered in the period
     * @param  string  $previousCredit  credit carried over from the previous return
     * @param  string  $previousDebit  debt below the minimum payment carried over from the previous return
     * @param  string  $regime  effective, net_tax_rate (Saldosteuersatzmethode), or flat_tax_rate (Pauschalsteuersatzmethode)
     * @param  ?string  $sssRate1  primary SSS rate percentage for Swiss net_tax_rate
     * @param  ?string  $sssRate2  secondary SSS rate percentage for Swiss net_tax_rate
     * @param  array<int, float|string>  $flatTaxRates  rates for Swiss flat_tax_rate
     * @param  bool  $carryOverBelowMinimum  Italy: a debt not above the minimum is carried to the next period; false pays it in its own period (the law only says it may be paid with the next one)
     */
    public static function compute(
        string $country,
        DateTimeInterface $from,
        DateTimeInterface $to,
        array $sales,
        array $purchases,
        string $previousCredit = '0',
        string $previousDebit = '0',
        string $regime = 'effective',
        ?string $sssRate1 = null,
        ?string $sssRate2 = null,
        array $flatTaxRates = [],
        bool $carryOverBelowMinimum = true,
    ): VatReturnResult {
        $italian = strcasecmp($country, 'IT') === 0;
        $isFlatRateSwiss = ! $italian && in_array($regime, ['net_tax_rate', 'flat_tax_rate'], true);
        $byRate = [];
        $turnover = '0';
        $grossSalesVat = '0';

        $deductionBoxes = [
            '220' => '0',
            '221' => '0',
            '225' => '0',
            '230' => '0',
            '235' => '0',
        ];

        foreach ($sales as $invoice) {
            $sign = stripos($invoice->invoiceType, 'credit') !== false ? '-1' : '1';
            $turnover = Decimal::add($turnover, Decimal::mul($sign, $invoice->totalNet));
            $vatCollected = ! ($italian && ($invoice->splitPayment || $invoice->reverseCharge));

            foreach ($invoice->items as $item) {
                $signedLineTotal = Decimal::mul($sign, $item->lineTotal);
                $hasPostedTax = $item->tax !== null && ! Decimal::isZero($item->tax);

                if (! Decimal::isPositive($item->taxRate) && ! $hasPostedTax) {
                    if (! $italian && Decimal::isPositive(Decimal::abs($signedLineTotal))) {
                        $boxKey = in_array($item->estvBox, ['220', '221', '225', '230', '235'], true) ? $item->estvBox : '220';
                        $deductionBoxes[$boxKey] = Decimal::add($deductionBoxes[$boxKey], $signedLineTotal);
                    }

                    continue;
                }

                $postedTax = $vatCollected ? self::taxOf($item) : '0';
                $signedPostedTax = Decimal::mul($sign, $postedTax);
                $grossSalesVat = Decimal::add($grossSalesVat, $signedPostedTax);

                if ($isFlatRateSwiss) {
                    $defaultFlatRate = $regime === 'net_tax_rate'
                        ? ($sssRate1 ?? '6.2')
                        : (isset($flatTaxRates[0]) ? (string) $flatTaxRates[0] : ($sssRate1 ?? '6.2'));
                    $effectiveSssRate = $item->sssRatePercent !== null && Decimal::isPositive($item->sssRatePercent)
                        ? Decimal::formatRate($item->sssRatePercent)
                        : Decimal::formatRate((string) $defaultFlatRate);

                    $grossConsideration = Decimal::add($signedLineTotal, $signedPostedTax);
                    $flatTax = Decimal::round2(Decimal::div(Decimal::mul($grossConsideration, $effectiveSssRate), '100'));

                    $current = $byRate[$effectiveSssRate] ?? ['taxable' => '0', 'tax' => '0'];
                    $byRate[$effectiveSssRate] = [
                        'taxable' => Decimal::add($current['taxable'], $grossConsideration),
                        'tax' => Decimal::add($current['tax'], $flatTax),
                    ];
                } else {
                    $key = Decimal::formatRate($item->taxRate);
                    $current = $byRate[$key] ?? ['taxable' => '0', 'tax' => '0'];
                    $byRate[$key] = [
                        'taxable' => Decimal::add($current['taxable'], $signedLineTotal),
                        'tax' => Decimal::add($current['tax'], $signedPostedTax),
                    ];
                }
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

        $rawInput = '0';
        $acquisitionTaxBase = '0';
        $acquisitionTaxAmount = '0';
        $inputBox400 = '0';
        $inputBox405 = '0';
        $inputBox410 = '0';
        $inputBox415 = '0';
        $inputBox420 = '0';

        foreach ($purchases as $purchase) {
            $rawInput = Decimal::add($rawInput, $purchase->deductibleVat);

            if (! $italian && ($purchase->reverseCharge || $purchase->estvBox === '383')) {
                $acquisitionTaxBase = Decimal::add($acquisitionTaxBase, $purchase->totalNet);
                $acqTax = Decimal::isPositive($purchase->acquisitionTaxAmount)
                    ? $purchase->acquisitionTaxAmount
                    : (Decimal::isPositive($purchase->deductibleVat)
                        ? $purchase->deductibleVat
                        : Decimal::round2(Decimal::div(Decimal::mul($purchase->totalNet, '8.1'), '100')));
                $acquisitionTaxAmount = Decimal::add($acquisitionTaxAmount, $acqTax);
            }

            if (! $italian) {
                match ($purchase->estvBox) {
                    '405' => $inputBox405 = Decimal::add($inputBox405, $purchase->deductibleVat),
                    '410' => $inputBox410 = Decimal::add($inputBox410, $purchase->deductibleVat),
                    '415' => $inputBox415 = Decimal::add($inputBox415, $purchase->deductibleVat),
                    '420' => $inputBox420 = Decimal::add($inputBox420, $purchase->deductibleVat),
                    default => $inputBox400 = Decimal::add($inputBox400, $purchase->deductibleVat),
                };
            }
        }

        if (! $italian && Decimal::isPositive($acquisitionTaxAmount)) {
            $output = Decimal::add($output, $acquisitionTaxAmount);
        }

        $input = $isFlatRateSwiss ? '0' : $rawInput;
        $forfeitedInput = $isFlatRateSwiss ? $rawInput : '0';
        $flatRateAdjustment = $isFlatRateSwiss ? Decimal::sub($grossSalesVat, Decimal::sub($output, $acquisitionTaxAmount)) : '0';

        $details[] = sprintf('IVA precedente detraibile: %s (%d fatture fornitori)', Decimal::formatGrouped($input), count($purchases));

        $balance = Decimal::add(Decimal::sub(Decimal::sub($output, $input), $previousCredit), $previousDebit);
        $interest = '0';

        if ($italian && Decimal::isPositive($balance) && self::isLongerThanOneMonth($from, $to)) {
            $interest = Decimal::round2(Decimal::mul($balance, '0.01'));
            $details[] = sprintf('Interessi trimestrali 1%%: %s', Decimal::formatGrouped($interest));
        }

        $payable = Decimal::add($balance, $interest);
        $belowMinimum = $italian
            && $carryOverBelowMinimum
            && Decimal::isPositive($payable)
            && Decimal::compare($payable, self::ITALIAN_MINIMUM_PAYMENT) <= 0;

        if ($belowMinimum) {
            $details[] = 'Importo non superiore a 100 EUR: non si versa, si riporta al periodo successivo.';
        }

        $grossTurnover = $isFlatRateSwiss ? Decimal::add($turnover, $grossSalesVat) : $turnover;
        $exemptSum = array_reduce($deductionBoxes, static fn (string $acc, string $val): string => Decimal::add($acc, $val), '0');

        if (! $isFlatRateSwiss && Decimal::compare($turnover, $taxableTurnover) > 0 && Decimal::isZero($exemptSum)) {
            $deductionBoxes['220'] = Decimal::sub($turnover, $taxableTurnover);
            $exemptSum = $deductionBoxes['220'];
        }

        $estvBoxes = $italian ? [] : self::buildSwissEstvBoxes(
            regime: $regime,
            turnover: $isFlatRateSwiss ? Decimal::add($taxableTurnover, $exemptSum) : $turnover,
            taxableTurnover: $taxableTurnover,
            deductionBoxes: $deductionBoxes,
            rates: $rates,
            acquisitionTaxBase: $acquisitionTaxBase,
            acquisitionTaxAmount: $acquisitionTaxAmount,
            outputVat: $output,
            inputBox400: $isFlatRateSwiss ? '0' : $inputBox400,
            inputBox405: $isFlatRateSwiss ? '0' : $inputBox405,
            inputBox410: $isFlatRateSwiss ? '0' : $inputBox410,
            inputBox415: $isFlatRateSwiss ? '0' : $inputBox415,
            inputBox420: $isFlatRateSwiss ? '0' : $inputBox420,
            inputVat: $input,
            payable: $payable,
        );

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
            regime: $regime,
            grossTurnover: Decimal::round2($grossTurnover),
            exemptTurnover: Decimal::round2($exemptSum),
            acquisitionTaxBase: Decimal::round2($acquisitionTaxBase),
            acquisitionTaxAmount: Decimal::round2($acquisitionTaxAmount),
            grossInputVatForfeited: Decimal::round2($forfeitedInput),
            flatRateAdjustmentAmount: Decimal::round2($flatRateAdjustment),
            estvBoxes: $estvBoxes,
        );
    }

    /**
     * Builds the complete 20-box ESTV Form 050/051 structure.
     *
     * @param  array<int|string, string>  $deductionBoxes
     * @param  list<VatRateTotal>  $rates
     * @return array<int|string, mixed>
     */
    private static function buildSwissEstvBoxes(
        string $regime,
        string $turnover,
        string $taxableTurnover,
        array $deductionBoxes,
        array $rates,
        string $acquisitionTaxBase,
        string $acquisitionTaxAmount,
        string $outputVat,
        string $inputBox400,
        string $inputBox405,
        string $inputBox410,
        string $inputBox415,
        string $inputBox420,
        string $inputVat,
        string $payable,
    ): array {
        $box289 = Decimal::round2(array_reduce($deductionBoxes, static fn (string $c, string $v): string => Decimal::add($c, $v), '0'));

        $box303 = ['base' => '0.00', 'rate' => '8.1', 'tax' => '0.00'];
        $box313 = ['base' => '0.00', 'rate' => '2.6', 'tax' => '0.00'];
        $box343 = ['base' => '0.00', 'rate' => '3.8', 'tax' => '0.00'];
        $sssBreakdown = [];

        if (in_array($regime, ['net_tax_rate', 'flat_tax_rate'], true)) {
            foreach ($rates as $idx => $rateTotal) {
                $sssBreakdown[] = [
                    'box' => $idx === 0 ? '322' : '332',
                    'base' => Decimal::round2($rateTotal->taxable),
                    'rate' => $rateTotal->rate,
                    'tax' => Decimal::round2($rateTotal->tax),
                ];
                if ($idx === 0) {
                    $box303 = ['base' => Decimal::round2($rateTotal->taxable), 'rate' => $rateTotal->rate, 'tax' => Decimal::round2($rateTotal->tax)];
                } elseif ($idx === 1) {
                    $box313 = ['base' => Decimal::round2($rateTotal->taxable), 'rate' => $rateTotal->rate, 'tax' => Decimal::round2($rateTotal->tax)];
                }
            }
        } else {
            foreach ($rates as $rateTotal) {
                if (in_array($rateTotal->rate, ['8.1', '7.7'], true)) {
                    $box303 = [
                        'base' => Decimal::round2(Decimal::add($box303['base'], $rateTotal->taxable)),
                        'rate' => $rateTotal->rate,
                        'tax' => Decimal::round2(Decimal::add($box303['tax'], $rateTotal->tax)),
                    ];
                } elseif (in_array($rateTotal->rate, ['2.6', '2.5'], true)) {
                    $box313 = [
                        'base' => Decimal::round2(Decimal::add($box313['base'], $rateTotal->taxable)),
                        'rate' => $rateTotal->rate,
                        'tax' => Decimal::round2(Decimal::add($box313['tax'], $rateTotal->tax)),
                    ];
                } elseif (in_array($rateTotal->rate, ['3.8', '3.7'], true)) {
                    $box343 = [
                        'base' => Decimal::round2(Decimal::add($box343['base'], $rateTotal->taxable)),
                        'rate' => $rateTotal->rate,
                        'tax' => Decimal::round2(Decimal::add($box343['tax'], $rateTotal->tax)),
                    ];
                }
            }
        }

        return [
            '200' => Decimal::round2($turnover),
            '220' => Decimal::round2(array_key_exists(220, $deductionBoxes) ? $deductionBoxes[220] : '0'),
            '221' => Decimal::round2(array_key_exists(221, $deductionBoxes) ? $deductionBoxes[221] : '0'),
            '225' => Decimal::round2(array_key_exists(225, $deductionBoxes) ? $deductionBoxes[225] : '0'),
            '230' => Decimal::round2(array_key_exists(230, $deductionBoxes) ? $deductionBoxes[230] : '0'),
            '235' => Decimal::round2(array_key_exists(235, $deductionBoxes) ? $deductionBoxes[235] : '0'),
            '289' => $box289,
            '299' => Decimal::round2($taxableTurnover),
            '303' => $box303,
            '313' => $box313,
            '343' => $box343,
            '383' => [
                'base' => Decimal::round2($acquisitionTaxBase),
                'rate' => '8.1',
                'tax' => Decimal::round2($acquisitionTaxAmount),
            ],
            '399' => Decimal::round2($outputVat),
            '400' => Decimal::round2($inputBox400),
            '405' => Decimal::round2($inputBox405),
            '410' => Decimal::round2($inputBox410),
            '415' => Decimal::round2($inputBox415),
            '420' => Decimal::round2($inputBox420),
            '479' => Decimal::round2($inputVat),
            '500' => Decimal::isPositive($payable) ? Decimal::round2($payable) : '0.00',
            '510' => Decimal::isNegative($payable) ? Decimal::round2(Decimal::neg($payable)) : '0.00',
            'sss_rates' => $sssBreakdown,
        ];
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
