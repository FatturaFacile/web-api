<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\ItalianVatPeriod;
use Modules\GeneralLedger\Services\Tax\LipeData;
use Modules\GeneralLedger\Services\Tax\LipeXmlBuilder;
use Modules\GeneralLedger\Services\Tax\VatPeriod;
use Modules\GeneralLedger\Services\Tax\VatPurchaseDocument;
use Modules\GeneralLedger\Services\Tax\VatReturnCalculator;
use Modules\GeneralLedger\Services\Tax\VatSaleDocument;
use Modules\GeneralLedger\Services\Tax\VatSaleItem;

test('VatReturn_Swiss_EffectiveMethod (desktop case)', function (): void {
    $from = CarbonImmutable::parse('2026-07-01');

    $result = VatReturnCalculator::compute(
        'CH',
        $from,
        $from->addMonths(6)->subDay(),
        [new VatSaleDocument('1000', [new VatSaleItem('1000', '8.1')])],
        [new VatPurchaseDocument('50')],
        '0',
    );

    expect($result->country)->toBe('CH')
        ->and($result->turnover)->toBe('1000.00')
        ->and($result->outputVat)->toBe('81.00')
        ->and($result->inputVat)->toBe('50.00')
        ->and($result->payable)->toBe('31.00')
        ->and($result->interest)->toBe('0.00');
});

test('VatReturn_Italian_QuarterlyWithSplitPaymentAndInterest (desktop case)', function (): void {
    $result = VatReturnCalculator::compute(
        'IT',
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-03-31'),
        [
            new VatSaleDocument('1000', [new VatSaleItem('1000', '22')]),
            new VatSaleDocument('500', [new VatSaleItem('500', '22')], splitPayment: true),
        ],
        [new VatPurchaseDocument('100')],
        '20',
    );

    expect($result->outputVat)->toBe('220.00')
        ->and($result->interest)->toBe('1.00')
        ->and($result->payable)->toBe('101.00')
        ->and($result->taxableTurnover)->toBe('1500.00')
        ->and($result->turnover)->toBe('1500.00');
});

test('credit notes reduce turnover, taxable amount and tax', function (): void {
    $result = VatReturnCalculator::compute(
        'CH',
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-03-31'),
        [
            new VatSaleDocument('1000', [new VatSaleItem('1000', '8.1')]),
            new VatSaleDocument('200', [new VatSaleItem('200', '8.1')], invoiceType: 'credit_note'),
        ],
        [],
    );

    expect($result->turnover)->toBe('800.00')
        ->and($result->taxableTurnover)->toBe('800.00')
        ->and($result->outputVat)->toBe('64.80')
        ->and($result->payable)->toBe('64.80');
});

test('tax is rounded line by line half away from zero, as Math.Round AwayFromZero', function (): void {
    $result = VatReturnCalculator::compute(
        'IT',
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-01-31'),
        [new VatSaleDocument('20.50', [
            new VatSaleItem('10.25', '22'),
            new VatSaleItem('10.25', '22'),
        ])],
        [],
    );

    // 10.25 * 22% = 2.255 → 2.26 for each line; rounding the sum instead would give 4.51.
    expect($result->outputVat)->toBe('4.52');
});

test('rates are ordered from the highest and summarized in the details', function (): void {
    $result = VatReturnCalculator::compute(
        'CH',
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-03-31'),
        [new VatSaleDocument('4000', [
            new VatSaleItem('1000', '2.6'),
            new VatSaleItem('2000', '8.1'),
            new VatSaleItem('1000', '0'),
        ])],
        [new VatPurchaseDocument('1234.5')],
    );

    expect(array_map(fn ($rate) => $rate->rate, $result->rates))->toBe(['8.1', '2.6'])
        ->and($result->taxableTurnover)->toBe('3000.00')
        ->and($result->turnover)->toBe('4000.00')
        ->and($result->details)->toBe(implode(PHP_EOL, [
            '8.1%: imponibile 2,000.00, imposta 162.00',
            '2.6%: imponibile 1,000.00, imposta 26.00',
            'IVA precedente detraibile: 1,234.50 (1 fatture fornitori)',
        ]));
});

test('a monthly Italian return carries no interest and a Swiss one never does', function (): void {
    $sale = [new VatSaleDocument('1000', [new VatSaleItem('1000', '22')])];

    $monthly = VatReturnCalculator::compute('IT', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'), $sale, []);
    $swiss = VatReturnCalculator::compute('CH', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'), $sale, []);

    expect($monthly->interest)->toBe('0.00')
        ->and($swiss->interest)->toBe('0.00');
});

test('a credit balance carries no interest and stays negative', function (): void {
    $result = VatReturnCalculator::compute(
        'IT',
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-03-31'),
        [new VatSaleDocument('100', [new VatSaleItem('100', '22')])],
        [new VatPurchaseDocument('500')],
        '10',
    );

    expect($result->interest)->toBe('0.00')
        ->and($result->payable)->toBe('-488.00');
});

test('an Italian debt below the minimum payment is flagged and the amount is unchanged', function (): void {
    $result = VatReturnCalculator::compute(
        'IT',
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-01-31'),
        [new VatSaleDocument('100', [new VatSaleItem('100', '22')])],
        [],
    );

    expect($result->payable)->toBe('22.00')
        ->and($result->belowMinimumPayment)->toBeTrue()
        ->and($result->details)->toContain('Importo non superiore a 100 EUR');
});

test('the minimum payment does not apply above 100.00 nor to Swiss taxpayers', function (): void {
    $from = CarbonImmutable::parse('2026-01-01');
    $to = CarbonImmutable::parse('2026-01-31');

    $exact = VatReturnCalculator::compute('IT', $from, $to, [new VatSaleDocument('100', [new VatSaleItem('100', '22', '100.01')])], []);
    $swiss = VatReturnCalculator::compute('CH', $from, $to, [new VatSaleDocument('100', [new VatSaleItem('100', '8.1')])], []);

    expect($exact->belowMinimumPayment)->toBeFalse()
        ->and($swiss->belowMinimumPayment)->toBeFalse();
});

test('the tax posted in the ledger takes the place of the computed one', function (): void {
    $result = VatReturnCalculator::compute(
        'CH',
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-03-31'),
        [new VatSaleDocument('100', [new VatSaleItem('100', '8.1', '8.09')])],
        [],
    );

    expect($result->outputVat)->toBe('8.09');
});

test('a debt carried over adds to the balance', function (): void {
    $result = VatReturnCalculator::compute(
        'IT',
        CarbonImmutable::parse('2026-02-01'),
        CarbonImmutable::parse('2026-02-28'),
        [new VatSaleDocument('100', [new VatSaleItem('100', '22')])],
        [],
        '0',
        '90',
    );

    expect($result->previousDebit)->toBe('90.00')
        ->and($result->payable)->toBe('112.00')
        ->and($result->belowMinimumPayment)->toBeFalse();
});

test('Lipe_WritesTheQuarterlyCommunication (desktop case)', function (): void {
    $data = new LipeData(
        taxCode: '01234567890',
        vatNumber: '01234567890',
        year: 2026,
        quarter: 3,
        activeOperations: '10000',
        passiveOperations: '4000',
        outputVat: '2200',
        inputVat: '880',
        interest: '13.20',
        payable: '1333.20',
    );

    $xml = LipeXmlBuilder::build($data);

    expect($xml)->toContain('urn:www.agenziaentrate.gov.it:specificheTecniche:sco:ivp')
        ->and($xml)->toContain('<iv:Trimestre>3</iv:Trimestre>')
        ->and($xml)->toContain('<iv:IvaDovuta>1320,00</iv:IvaDovuta>')
        ->and($xml)->toContain('<iv:ImportoDaVersare>1333,20</iv:ImportoDaVersare>')
        ->and($xml)->not->toContain('IvaCredito')
        ->and(LipeXmlBuilder::fileName($data))->toBe('IT01234567890_LI_26T03.xml')
        ->and(ItalianVatPeriod::f24TaxCode(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-09-30')))->toBe('6033')
        ->and(ItalianVatPeriod::f24TaxCode(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31')))->toBe('6010');
});

test('the LIPE of a monthly credit has the month, the credit fields and the declarant', function (): void {
    $data = new LipeData(
        taxCode: 'RSSMRA80A01H501U',
        vatNumber: '01234567890',
        year: 2027,
        month: 2,
        declarantTaxCode: 'VRDLGI70A01H501X',
        outputVat: '100',
        inputVat: '300.5',
        previousCredit: '10',
        payable: '-210.5',
    );

    $xml = LipeXmlBuilder::build($data);

    expect($xml)->toContain('<iv:Mese>2</iv:Mese>')
        ->and($xml)->toContain('<iv:IvaCredito>200,50</iv:IvaCredito>')
        ->and($xml)->toContain('<iv:ImportoACredito>210,50</iv:ImportoACredito>')
        ->and($xml)->toContain('<iv:CreditoPeriodoPrecedente>10,00</iv:CreditoPeriodoPrecedente>')
        ->and($xml)->toContain('<iv:CodiceFiscaleDichiarante>VRDLGI70A01H501X</iv:CodiceFiscaleDichiarante>')
        ->and($xml)->toContain('<iv:CFDichiarante>VRDLGI70A01H501X</iv:CFDichiarante>')
        ->and($xml)->not->toContain('ImportoDaVersare')
        ->and(LipeXmlBuilder::fileName($data))->toBe('ITRSSMRA80A01H501U_LI_27M02.xml');

    $parsed = new DOMDocument;
    expect($parsed->loadXML($xml))->toBeTrue()
        ->and($parsed->documentElement->namespaceURI)->toBe(LipeXmlBuilder::NAMESPACE_URI);
});

test('F24 tax codes cover every month and quarter', function (string $from, string $to, string $code): void {
    expect(ItalianVatPeriod::f24TaxCode(CarbonImmutable::parse($from), CarbonImmutable::parse($to)))->toBe($code);
})->with([
    ['2026-01-01', '2026-01-31', '6001'],
    ['2026-12-01', '2026-12-31', '6012'],
    ['2026-01-01', '2026-03-31', '6031'],
    ['2026-04-01', '2026-06-30', '6032'],
    ['2026-07-01', '2026-09-30', '6033'],
    ['2026-10-01', '2026-12-31', '6099'],
]);

test('amounts are exact on values a float would corrupt', function (): void {
    expect(Decimal::add('0.1', '0.2'))->toBe('0.3000000000')
        ->and(Decimal::round2('1.005'))->toBe('1.01')
        ->and(Decimal::round2('-1.005'))->toBe('-1.01')
        ->and(Decimal::round2('-0.001'))->toBe('0.00')
        ->and(Decimal::formatGrouped('-1234567.5'))->toBe('-1,234,567.50')
        ->and(Decimal::formatRate('22.00'))->toBe('22')
        ->and(Decimal::formatRate('8.10'))->toBe('8.1')
        ->and(fn () => Decimal::normalize('1e3'))->toThrow(InvalidArgumentException::class);
});

test('VAT periods are recognized from the calendar', function (): void {
    expect(VatPeriod::of('monthly', 2026, 2)->to->toDateString())->toBe('2026-02-28')
        ->and(VatPeriod::of('quarterly', 2026, 4)->from->toDateString())->toBe('2026-10-01')
        ->and(VatPeriod::of('semiannual', 2026, 2)->label())->toBe('2026-S2')
        ->and(VatPeriod::fromDates(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'))->label())->toBe('2026-M01')
        ->and(VatPeriod::fromDates(CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-06-30'))->label())->toBe('2026-Q2')
        ->and(fn () => VatPeriod::fromDates(CarbonImmutable::parse('2026-01-05'), CarbonImmutable::parse('2026-02-04')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => VatPeriod::of('quarterly', 2026, 5))->toThrow(InvalidArgumentException::class);
});
