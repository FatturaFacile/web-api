<?php

declare(strict_types=1);

use Modules\GeneralLedger\Services\Tax\LipeBoxes;
use Modules\GeneralLedger\Services\Tax\LipeData;
use Modules\GeneralLedger\Services\Tax\LipeXmlBuilder;
use Modules\GeneralLedger\Services\Tax\LipeXsdValidator;
use Modules\GeneralLedger\Services\Tax\SwissRounding;

it('rounds Swiss VAT amounts to 0.05 CHF half away from zero', function (string $input, string $expected): void {
    expect(SwissRounding::to05($input))->toBe($expected);
})->with([
    ['12.34', '12.35'],
    ['12.32', '12.30'],
    ['12.325', '12.35'],
    ['-12.325', '-12.35'],
    ['0.02', '0.00'],
    ['0.03', '0.05'],
    ['100.00', '100.00'],
]);

it('computes LIPE VP2-VP14 for a quarterly payer with previous credit and interest', function (): void {
    $boxes = LipeBoxes::compute('5000', '2000', '1000', '400', '0', '100', false, true);

    expect($boxes->toArray())->toMatchArray([
        'VP2' => '5000.00',
        'VP3' => '2000.00',
        'VP4' => '1000.00',
        'VP5' => '400.00',
        'VP6_due' => '600.00',
        'VP6_credit' => '0.00',
        'VP8' => '100.00',
        'VP9' => '0.00',
        'VP12' => '5.00',
        'VP14_payable' => '505.00',
        'VP14_credit' => '0.00',
    ]);
});

it('books the credit as previous-year credit (VP9) in the first period of the year', function (): void {
    $boxes = LipeBoxes::compute('0', '0', '100', '0', '0', '40', true, false);

    expect($boxes->vp8PreviousPeriodCredit)->toBe('0.00')
        ->and($boxes->vp9PreviousYearCredit)->toBe('40.00')
        ->and($boxes->vp14Payable)->toBe('60.00')
        ->and($boxes->vp12Interest)->toBe('0.00');
});

it('applies the pro-rata deductibility to the input VAT and reports a credit', function (): void {
    $boxes = LipeBoxes::compute('0', '1000', '100', '400', '0', '0', false, false, '50', '0');

    expect($boxes->vp5DeductibleVat)->toBe('200.00')
        ->and($boxes->vp6Due)->toBe('0.00');

    $credit = LipeBoxes::compute('0', '1000', '100', '400', '0', '0', false, false, '100');

    expect($credit->vp6Credit)->toBe('300.00')
        ->and($credit->vp14Credit)->toBe('300.00')
        ->and($credit->vp14Payable)->toBe('0.00');
});

it('subtracts the advance (VP13) from the amount to pay', function (): void {
    $boxes = LipeBoxes::compute('0', '0', '1000', '0', '0', '0', false, false, '100', '300');

    expect($boxes->vp13Advance)->toBe('300.00')->and($boxes->vp14Payable)->toBe('700.00');
});

it('validates the LIPE XML against the schema profile', function (): void {
    $data = new LipeData(
        taxCode: '01234567890',
        vatNumber: '01234567890',
        year: 2026,
        quarter: 1,
        activeOperations: '5000',
        passiveOperations: '2000',
        outputVat: '1000',
        inputVat: '400',
        previousCredit: '100',
        interest: '5',
        payable: '505',
        advance: '0',
    );

    $xml = LipeXmlBuilder::build($data);

    expect(LipeXsdValidator::errors($xml))->toBe([])
        ->and($xml)->toContain('1000,00')->toContain('ImportoDaVersare');
});

it('rejects a LIPE XML that breaks the schema profile', function (): void {
    $data = new LipeData(taxCode: '01234567890', vatNumber: '1234', year: 2026, month: 3, outputVat: '10');

    expect(LipeXsdValidator::errors(LipeXmlBuilder::build($data)))->not->toBe([])
        ->and(LipeXsdValidator::errors('<broken'))->not->toBe([]);
});
