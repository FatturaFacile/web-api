<?php

declare(strict_types=1);

use Modules\HumanResources\DTOs\Payroll\WithholdingTariffData;
use Modules\HumanResources\Exceptions\Payroll\MissingWithholdingTariffException;
use Modules\HumanResources\Services\Payroll\WithholdingTaxService;
use Tests\Unit\HumanResources\Payroll\CalculatorFixtures;

test('the church tax flag selects the Y or N variant of the tariff code', function (string $code, bool $churchTax, string $expected): void {
    expect((new WithholdingTaxService)->effectiveTariffCode($code, $churchTax))->toBe($expected);
})->with([
    ['A0N', true, 'A0Y'],
    ['A0Y', false, 'A0N'],
    ['b2n', false, 'B2N'],
    ['C1N', false, 'C1N'],
]);

test('tax is the bracket rate applied on the whole taxable income, rounded to 0.05 CHF', function (): void {
    $tariff = CalculatorFixtures::tariff();

    expect(WithholdingTaxService::computeTax($tariff, 800_000))->toBe(76_000)
        ->and(WithholdingTaxService::computeTax($tariff, 600_001))->toBe(30_000)
        ->and(WithholdingTaxService::computeTax($tariff, 1_000_000))->toBe(120_000)
        ->and(WithholdingTaxService::computeTax($tariff, 0))->toBe(0);
});

test('an income in the 0.05 CHF gap between two brackets uses the lower bracket', function (): void {
    $tariff = CalculatorFixtures::tariff();

    // 7'000.02 falls between 7'000.00 and 7'000.05: 5% -> 350.00
    expect(WithholdingTaxService::computeTax($tariff, 700_002))->toBe(35_000);
});

test('an income outside every bracket raises a missing tariff error', function (): void {
    $tariff = new WithholdingTariffData('TI', 'A0N', '2026-01-01', [
        ['income_from_rappen' => 0, 'income_to_rappen' => 500_000, 'rate_ppm' => 50_000, 'fixed_amount_rappen' => 0],
    ]);

    WithholdingTaxService::computeTax($tariff, 500_100, 'employee-1');
})->throws(MissingWithholdingTariffException::class);
