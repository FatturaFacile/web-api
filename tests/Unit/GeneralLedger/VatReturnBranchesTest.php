<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\Tax\VatPurchaseDocument;
use Modules\GeneralLedger\Services\Tax\VatReturnCalculator;
use Modules\GeneralLedger\Services\Tax\VatSaleDocument;
use Modules\GeneralLedger\Services\Tax\VatSaleItem;

/**
 * Branches of the Swiss VAT statement that no other test reached (measured with pcov): the flat tax rate method (the
 * rate comes from the list given to the calculator), the acquisition tax defaults, the input tax boxes and the exempt
 * turnover boxes. Expected values are computed by hand; the standard rate of 8.1% is the ESTV rate valid from 2024-01-01
 * (ESTV, "Schweizer Mehrwertsteuersätze", read 2026-11-11). The flat tax rates themselves are chosen by the taxpayer
 * with the ESTV and are used here as plain inputs, not asserted as legal rates.
 *
 * @return array{0: CarbonImmutable, 1: CarbonImmutable}
 */
function branchPeriod(): array
{
    return [CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-06-30')];
}

test('the flat tax rate method takes the rate from the list and taxes the gross consideration', function (): void {
    [$from, $to] = branchPeriod();

    // Gross consideration 1000.00 + 81.00 = 1081.00; at 4.5%: 48.645, rounded half up to 48.65. No input tax.
    $result = VatReturnCalculator::compute(
        'CH',
        $from,
        $to,
        [new VatSaleDocument('1000.00', [new VatSaleItem('1000.00', '8.1')])],
        [new VatPurchaseDocument('30.00')],
        '0',
        '0',
        'flat_tax_rate',
        null,
        null,
        ['4.5'],
    );

    expect($result->outputVat)->toBe('48.65')
        ->and($result->inputVat)->toBe('0.00')
        ->and($result->payable)->toBe('48.65')
        ->and($result->grossInputVatForfeited)->toBe('30.00');
});

test('a rate stated on the line wins over the default of the flat rate method', function (): void {
    [$from, $to] = branchPeriod();

    // 1081.00 x 6.2% = 67.022 -> 67.02 on the first line; 540.50 x 2.1% = 11.3505 -> 11.35 on the second.
    $result = VatReturnCalculator::compute(
        'CH',
        $from,
        $to,
        [new VatSaleDocument('1500.00', [new VatSaleItem('1000.00', '8.1', null, '6.2'), new VatSaleItem('500.00', '8.1', null, '2.1')])],
        [],
        '0',
        '0',
        'net_tax_rate',
        '6.2',
        '2.1',
    );

    expect($result->outputVat)->toBe('78.37');
});

test('the acquisition tax defaults to the deductible VAT, then to 8.1% of the net', function (): void {
    [$from, $to] = branchPeriod();

    // No amount stated and no deductible VAT: 8.1% of 1000.00 = 81.00.
    $computed = VatReturnCalculator::compute('CH', $from, $to, [], [new VatPurchaseDocument('0', '1000.00', true)]);
    // No amount stated, deductible VAT 40.50: that is the tax declared.
    $fromDeductible = VatReturnCalculator::compute('CH', $from, $to, [], [new VatPurchaseDocument('40.50', '1000.00', true)]);

    expect($computed->acquisitionTaxAmount)->toBe('81.00')
        ->and($computed->outputVat)->toBe('81.00')
        ->and($computed->payable)->toBe('81.00')
        ->and($fromDeductible->acquisitionTaxAmount)->toBe('40.50')
        ->and($fromDeductible->outputVat)->toBe('40.50')
        ->and($fromDeductible->payable)->toBe('0.00');
});

test('input tax is reported in the box of its kind', function (): void {
    [$from, $to] = branchPeriod();

    $result = VatReturnCalculator::compute('CH', $from, $to, [], [
        new VatPurchaseDocument('10.00', '0', false, '400'),
        new VatPurchaseDocument('20.00', '0', false, '405'),
        new VatPurchaseDocument('30.00', '0', false, '410'),
        new VatPurchaseDocument('40.00', '0', false, '415'),
        new VatPurchaseDocument('50.00', '0', false, '420'),
        new VatPurchaseDocument('5.00'),
    ]);

    expect($result->estvBoxes['400'])->toBe('15.00')
        ->and($result->estvBoxes['405'])->toBe('20.00')
        ->and($result->estvBoxes['410'])->toBe('30.00')
        ->and($result->estvBoxes['415'])->toBe('40.00')
        ->and($result->estvBoxes['420'])->toBe('50.00')
        ->and($result->estvBoxes['479'])->toBe('155.00')
        ->and($result->estvBoxes['510'])->toBe('155.00');
});

test('exempt turnover goes in the box stated on the line, and in 220 when none is', function (): void {
    [$from, $to] = branchPeriod();

    $result = VatReturnCalculator::compute('CH', $from, $to, [
        new VatSaleDocument('1700.00', [
            new VatSaleItem('1000.00', '8.1'),
            new VatSaleItem('300.00', '0', null, null, '225'),
            new VatSaleItem('250.00', '0', null, null, '230'),
            new VatSaleItem('150.00', '0'),
        ]),
    ], []);

    expect($result->estvBoxes['225'])->toBe('300.00')
        ->and($result->estvBoxes['230'])->toBe('250.00')
        ->and($result->estvBoxes['220'])->toBe('150.00')
        ->and($result->estvBoxes['289'])->toBe('700.00')
        ->and($result->estvBoxes['299'])->toBe('1000.00');
});
