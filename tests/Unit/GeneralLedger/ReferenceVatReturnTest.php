<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\Tax\VatPurchaseDocument;
use Modules\GeneralLedger\Services\Tax\VatReturnCalculator;
use Modules\GeneralLedger\Services\Tax\VatSaleDocument;
use Modules\GeneralLedger\Services\Tax\VatSaleItem;

/**
 * Independent reference check of the periodic VAT return (Italian liquidation and Swiss MWST statement).
 *
 * The expected values are computed here with plain bcmath, not with the production Decimal class, and rounded to two
 * decimals half away from zero. Rules and figures, each with its source:
 *
 * - Italian periodic liquidation: VAT of the period = output VAT (sales without split payment and without reverse charge,
 *   credit notes negative) - deductible VAT - credit carried over + debt carried over; quarterly taxpayers add 1% of the
 *   debt (art. 7 D.P.R. 542/1999; Agenzia delle Entrate, "F24 IVA periodica - come e quando si versa", read 2026-11-11: "In
 *   caso di versamento trimestrale l'eventuale debito d'imposta deve essere maggiorato dell'1%"; the instructions of the LIPE
 *   communication 2024 say the fourth quarter is paid with the annual balance, including the 1% interest).
 * - A periodic debt not exceeding EUR 100 is carried to the next period: Agenzia delle Entrate circolare 9/E of 2 May 2024
 *   (raised from EUR 25.82), rigo VP7 of the LIPE communication 2024 ("debito periodo precedente non superiore a 100 euro").
 * - Split payment (art. 17-ter D.P.R. 633/1972): the supplier does not collect the VAT, so it is not part of its
 *   liquidation. Reverse charge: the supplier issues without VAT.
 * - Swiss MWST rates valid from 2024-01-01: 8.1% standard, 2.6% reduced, 3.8% accommodation (ESTV, "Schweizer
 *   Mehrwertsteuersätze", read 2026-11-11; the 3.8% special rate runs to the end of 2027). Rates up to 2023 (7.7, 2.5, 3.7)
 *   are handled as data, the calculator being rate agnostic.
 * - Swiss effective method: tax due = tax on turnover + acquisition tax - input tax - credit. Saldosteuersatz method:
 *   tax due = gross consideration (net + VAT collected) x the SSS rate (the ESTV example for architects is 6.2%), no input tax.
 *
 * Not verified against a primary source and therefore not asserted: the rounding of the Italian VAT per invoice line
 * versus per rate and document, and the exact ESTV form box numbering beyond the totals used here.
 */
function refR2(string $value): string
{
    return bcround($value, 2, RoundingMode::HalfAwayFromZero);
}

/**
 * @param  list<array{net: string, rate: string, kind?: string}>  $sales  kind: invoice, credit, split, reverse
 * @param  list<string>  $inputVat
 */
function refItalianLiquidation(array $sales, array $inputVat, string $credit, string $debit, bool $quarterly): array
{
    $output = '0';

    foreach ($sales as $sale) {
        $kind = $sale['kind'] ?? 'invoice';

        if ($kind === 'split' || $kind === 'reverse') {
            continue;
        }

        $tax = bcdiv(bcmul($sale['net'], $sale['rate'], 6), '100', 6);
        $output = bcadd($output, $kind === 'credit' ? bcmul($tax, '-1', 6) : $tax, 6);
    }

    $output = refR2($output);
    $input = '0';

    foreach ($inputVat as $vat) {
        $input = bcadd($input, $vat, 2);
    }

    $balance = bcadd(bcsub(bcsub($output, $input, 2), $credit, 2), $debit, 2);
    $interest = $quarterly && bccomp($balance, '0', 2) > 0 ? refR2(bcmul($balance, '0.01', 6)) : '0.00';
    $payable = bcadd($balance, $interest, 2);

    return [
        'output' => $output,
        'interest' => $interest,
        'payable' => $payable,
        'below' => bccomp($payable, '0', 2) > 0 && bccomp($payable, '100', 2) <= 0,
    ];
}

/**
 * @param  list<array{net: string, rate: string, kind?: string}>  $sales
 */
function refItalianDocuments(array $sales): array
{
    return array_map(static fn (array $sale): VatSaleDocument => new VatSaleDocument(
        $sale['net'],
        [new VatSaleItem($sale['net'], $sale['rate'])],
        ($sale['kind'] ?? 'invoice') === 'credit' ? 'credit_note' : 'invoice',
        ($sale['kind'] ?? 'invoice') === 'split',
        ($sale['kind'] ?? 'invoice') === 'reverse',
    ), $sales);
}

$monthly = ['2026-04-01', '2026-04-30'];
$quarterly = ['2026-04-01', '2026-06-30'];
$fourthQuarter = ['2026-10-01', '2026-12-31'];

dataset('italian liquidations', [
    'S1 monthly, one rate, input VAT' => [$monthly, [['net' => '1000.00', 'rate' => '22']], ['50.00'], '0', '0', '170.00'],
    'S2 quarterly adds 1% interest' => [$quarterly, [['net' => '1000.00', 'rate' => '22']], ['50.00'], '0', '0', '171.70'],
    'S3 credit note reduces the output' => [$monthly, [['net' => '1000.00', 'rate' => '22'], ['net' => '200.00', 'rate' => '22', 'kind' => 'credit']], [], '0', '0', '176.00'],
    'S4 three rates 22, 10, 4' => [$monthly, [['net' => '1000.00', 'rate' => '22'], ['net' => '500.00', 'rate' => '10'], ['net' => '200.00', 'rate' => '4']], [], '0', '0', '278.00'],
    'S5 split payment and reverse charge are out' => [$monthly, [['net' => '1000.00', 'rate' => '22'], ['net' => '800.00', 'rate' => '22', 'kind' => 'split'], ['net' => '300.00', 'rate' => '22', 'kind' => 'reverse']], ['20.00'], '0', '0', '200.00'],
    'S6 credit larger than the debt: negative, no interest' => [$quarterly, [['net' => '1000.00', 'rate' => '22']], ['300.00'], '0', '0', '-80.00'],
    'S7 credit carried over' => [$monthly, [['net' => '1000.00', 'rate' => '22']], [], '50.00', '0', '170.00'],
    'S8 debt carried over, exactly EUR 100 stays below the minimum' => [$monthly, [['net' => '300.00', 'rate' => '22']], [], '0', '34.00', '100.00'],
    'S9 debt carried over, EUR 100.01 is paid' => [$monthly, [['net' => '300.00', 'rate' => '22']], [], '0', '34.01', '100.01'],
    'S10 fourth quarter carries the 1% too' => [$fourthQuarter, [['net' => '5000.00', 'rate' => '22']], ['100.00'], '0', '0', '1010.00'],
    'S11 interest rounds half up' => [$quarterly, [['net' => '1000.00', 'rate' => '22']], ['70.50'], '0', '0', '151.00'],
]);

it('computes the Italian periodic liquidation like the hand reference', function (array $period, array $sales, array $inputVat, string $credit, string $debit, string $declaredPayable): void {
    $quarter = $period[1] !== '2026-04-30';
    $reference = refItalianLiquidation($sales, $inputVat, $credit, $debit, $quarter);
    $result = VatReturnCalculator::compute(
        'IT',
        CarbonImmutable::parse($period[0]),
        CarbonImmutable::parse($period[1]),
        refItalianDocuments($sales),
        array_map(static fn (string $vat): VatPurchaseDocument => new VatPurchaseDocument($vat), $inputVat),
        $credit,
        $debit,
    );

    // The scenario table states the expected payable by hand; the reference function must agree with it first.
    expect($reference['payable'])->toBe($declaredPayable)
        ->and($result->payable)->toBe($reference['payable'])
        ->and($result->interest)->toBe($reference['interest'])
        ->and($result->outputVat)->toBe($reference['output'])
        ->and($result->belowMinimumPayment)->toBe($reference['below']);
})->with('italian liquidations');

it('applies the hand computed figures of the quarterly scenarios', function (): void {
    // S2: (220 - 50) = 170; 1% = 1.70; 171.70.
    $s2 = VatReturnCalculator::compute('IT', CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-06-30'), refItalianDocuments([['net' => '1000.00', 'rate' => '22']]), [new VatPurchaseDocument('50.00')]);
    // S10: 5000 x 22% = 1100; - 100 = 1000; 1% = 10.00; 1010.00.
    $s10 = VatReturnCalculator::compute('IT', CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-12-31'), refItalianDocuments([['net' => '5000.00', 'rate' => '22']]), [new VatPurchaseDocument('100.00')]);
    // S11: 220 - 70.50 = 149.50; 1% = 1.495 -> 1.50; 151.00.
    $s11 = VatReturnCalculator::compute('IT', CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-06-30'), refItalianDocuments([['net' => '1000.00', 'rate' => '22']]), [new VatPurchaseDocument('70.50')]);

    expect($s2->payable)->toBe('171.70')
        ->and($s10->payable)->toBe('1010.00')
        ->and($s10->interest)->toBe('10.00')
        ->and($s11->interest)->toBe('1.50')
        ->and($s11->payable)->toBe('151.00');
});

/**
 * @param  list<array{net: string, rate: string, tax?: string, kind?: string}>  $sales
 */
function refSwissDocuments(array $sales): array
{
    return array_map(static fn (array $sale): VatSaleDocument => new VatSaleDocument(
        $sale['net'],
        [new VatSaleItem($sale['net'], $sale['rate'], $sale['tax'] ?? null)],
        ($sale['kind'] ?? 'invoice') === 'credit' ? 'credit_note' : 'invoice',
    ), $sales);
}

dataset('swiss statements', [
    'C1 standard rate' => [[['net' => '1000.00', 'rate' => '8.1']], '50.00', '0', '81.00', '31.00'],
    'C2 three current rates' => [[['net' => '1000.00', 'rate' => '8.1'], ['net' => '500.00', 'rate' => '2.6'], ['net' => '200.00', 'rate' => '3.8']], '0', '0', '101.60', '101.60'],
    'C3 credit note' => [[['net' => '1000.00', 'rate' => '8.1'], ['net' => '100.00', 'rate' => '8.1', 'kind' => 'credit']], '0', '0', '72.90', '72.90'],
    'C4 input tax above the output: refund' => [[['net' => '1000.00', 'rate' => '8.1']], '200.00', '0', '81.00', '-119.00'],
    'C5 credit carried over' => [[['net' => '1000.00', 'rate' => '8.1']], '0', '30.00', '81.00', '51.00'],
    'C6 legacy 2023 rates' => [[['net' => '1000.00', 'rate' => '7.7'], ['net' => '1000.00', 'rate' => '2.5']], '0', '0', '102.00', '102.00'],
    'C7 no interest over a quarter' => [[['net' => '10000.00', 'rate' => '8.1']], '0', '0', '810.00', '810.00'],
    'C8 the tax posted in the ledger wins' => [[['net' => '100.00', 'rate' => '8.1', 'tax' => '8.09']], '0', '0', '8.09', '8.09'],
]);

it('computes the Swiss effective method like the hand reference', function (array $sales, string $input, string $credit, string $output, string $payable): void {
    $result = VatReturnCalculator::compute(
        'CH',
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-06-30'),
        refSwissDocuments($sales),
        $input === '0' ? [] : [new VatPurchaseDocument($input)],
        $credit,
    );

    expect($result->outputVat)->toBe($output)
        ->and($result->payable)->toBe($payable)
        ->and($result->interest)->toBe('0.00')
        ->and($result->belowMinimumPayment)->toBeFalse();
})->with('swiss statements');

it('computes the Swiss acquisition tax on a reverse charge purchase (box 383) as output and input tax', function (): void {
    // CHF 1000 services bought abroad: acquisition tax 8.1% = 81.00 declared, and deducted as input tax (full deduction).
    $result = VatReturnCalculator::compute(
        'CH',
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-03-31'),
        refSwissDocuments([['net' => '2000.00', 'rate' => '8.1']]),
        [new VatPurchaseDocument('81.00', '1000.00', true, '383', '81.00')],
    );

    // output 162.00 + 81.00 = 243.00; input 81.00; payable 162.00.
    expect($result->outputVat)->toBe('243.00')
        ->and($result->inputVat)->toBe('81.00')
        ->and($result->payable)->toBe('162.00')
        ->and($result->acquisitionTaxBase)->toBe('1000.00')
        ->and($result->acquisitionTaxAmount)->toBe('81.00');
});

it('computes the Swiss net tax rate method on the gross consideration with no input tax', function (): void {
    // CHF 1000 + 81.00 VAT collected = 1081.00 x 6.2% = 67.022 -> 67.02; the input tax of 50.00 is forfeited.
    $result = VatReturnCalculator::compute(
        'CH',
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-06-30'),
        refSwissDocuments([['net' => '1000.00', 'rate' => '8.1']]),
        [new VatPurchaseDocument('50.00')],
        '0',
        '0',
        'net_tax_rate',
        '6.2',
    );

    expect($result->outputVat)->toBe('67.02')
        ->and($result->inputVat)->toBe('0.00')
        ->and($result->payable)->toBe('67.02')
        ->and($result->grossInputVatForfeited)->toBe('50.00');
});

it('reports exempt Swiss turnover without tax', function (): void {
    $result = VatReturnCalculator::compute(
        'CH',
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-03-31'),
        refSwissDocuments([['net' => '1000.00', 'rate' => '8.1'], ['net' => '400.00', 'rate' => '0']]),
        [],
    );

    expect($result->turnover)->toBe('1400.00')
        ->and($result->taxableTurnover)->toBe('1000.00')
        ->and($result->exemptTurnover)->toBe('400.00')
        ->and($result->outputVat)->toBe('81.00');
});
