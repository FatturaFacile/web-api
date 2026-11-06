<?php

declare(strict_types=1);

use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\PayslipLineData;
use Modules\Payroll\Calculation\Swiss\SalaryCertificateBoxes;
use Modules\Payroll\Calculation\Swiss\WithholdingTaxTariffParser;
use Modules\Payroll\Calculation\WageTypeKind;

test('quotients keep the digits of System.Decimal, rounded half to even', function (string $dividend, string $divisor, string $expected): void {
    expect(Dec::div($dividend, $divisor))->toBe($expected);
})->with([
    ['2', '3', '0.6666666666666666666666666667'],
    ['200000', '3', '66666.666666666666666666666667'],
    ['84500', '13', '6500'],
    ['100000', '13', '7692.3076923076923076923076923'],
]);

test('a repeating quotient multiplied back lands where it does in C#', function (): void {
    expect(Dec::mul(Dec::div('2', '3'), '3'))->toBe('2.0000000000000000000000000001');
});

test('rounding goes away from zero', function (string $value, int $places, string $expected): void {
    expect(Dec::round($value, $places))->toBe($expected);
})->with([
    ['1.125', 2, '1.13'],
    ['-1.125', 2, '-1.13'],
    ['2307.5', 0, '2308'],
    ['212.1052', 2, '212.11'],
]);

test('only plain decimal strings are accepted', function (): void {
    expect(Dec::of('5.3000'))->toBe('5.3')
        ->and(Dec::of('-0.00'))->toBe('0')
        ->and(fn () => Dec::of('1e3'))->toThrow(InvalidArgumentException::class);
});

test('ESTV record 06 fields are read at their fixed positions and deletions are skipped', function (): void {
    $header = '00ZH'.str_repeat(' ', 15).'20251201';
    $row = '0601ZH'.str_pad('A0N', 10).'20260101000500000000010000 0000000000000412   ';
    $deleted = '0603ZH'.str_pad('A0N', 10).'20250101000500000000010000 0000000000000400   ';

    $rows = WithholdingTaxTariffParser::parse(implode("\r\n", [$header, $row, $deleted, '99']));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->canton)->toBe('ZH')
        ->and($rows[0]->tariffCode)->toBe('A0N')
        ->and($rows[0]->validFrom->format('Y-m-d'))->toBe('2026-01-01')
        ->and(Dec::cmp($rows[0]->incomeFrom, '5000'))->toBe(0)
        ->and(Dec::cmp($rows[0]->incomeStep, '100'))->toBe(0)
        ->and(Dec::cmp($rows[0]->minimumTax, '0'))->toBe(0)
        ->and(Dec::cmp($rows[0]->ratePercent, '4.12'))->toBe(0)
        ->and(fn () => WithholdingTaxTariffParser::parse('00ZH'))->toThrow(InvalidArgumentException::class);
});

test('the certificate boxes sum the lines by box and code', function (): void {
    $line = static fn (string $code, string $kind, string $amount, ?string $box): PayslipLineData => new PayslipLineData($code, $code, $kind, '1', '0', $amount, 0, $box);

    $boxes = SalaryCertificateBoxes::fill([
        $line('1000', WageTypeKind::Earning, '60000.00', '1'),
        $line('1100', WageTypeKind::Earning, '5000.00', '3'),
        $line('4000', WageTypeKind::NonCash, '1200.00', '2.1'),
        $line('2000', WageTypeKind::Earning, '800.00', '7'),
        $line('5000', WageTypeKind::Expense, '300.00', '13.1.1'),
        $line('9000', WageTypeKind::Deduction, '-3500.00', '9'),
        $line('9010', WageTypeKind::Deduction, '-700.00', '9'),
        $line('9020', WageTypeKind::Deduction, '-600.00', '9'),
        $line('9030', WageTypeKind::Deduction, '-300.00', null),
        $line('9040', WageTypeKind::Deduction, '-2000.00', '10.1'),
        $line('9050', WageTypeKind::Deduction, '-4000.00', '12'),
    ]);

    expect(Dec::cmp($boxes['box1_salary'], '60000'))->toBe(0)
        ->and(Dec::cmp($boxes['box2_benefits'], '1200'))->toBe(0)
        ->and(Dec::cmp($boxes['box3_irregular'], '5000'))->toBe(0)
        ->and(Dec::cmp($boxes['box7_other'], '800'))->toBe(0)
        ->and(Dec::cmp($boxes['box8_gross'], '67000'))->toBe(0)
        ->and(Dec::cmp($boxes['box9_social_contributions'], '4800'))->toBe(0)
        ->and(Dec::cmp($boxes['box10_pension'], '2000'))->toBe(0)
        ->and(Dec::cmp($boxes['box11_net'], '60200'))->toBe(0)
        ->and(Dec::cmp($boxes['box12_withholding_tax'], '4000'))->toBe(0)
        ->and(Dec::cmp($boxes['box13_expenses'], '300'))->toBe(0);
});
