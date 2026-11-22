<?php

declare(strict_types=1);

use Modules\Projects\Support\SalCalculator;
use Modules\Projects\Support\TimeAndMaterialsCalculator;

test('the first SAL earns the incremental value, recoups the advance in proportion and withholds the retention', function (): void {
    $sal = SalCalculator::compute('100000', '30', '0', '0', '10000', '0', '5');

    expect($sal['cumulative_gross_earned_value'])->toBe('30000.00')
        ->and($sal['current_period_gross_sal'])->toBe('30000.00')
        ->and($sal['advance_deduction_amount'])->toBe('3000.00')
        ->and($sal['guarantee_retention_withheld'])->toBe('1500.00')
        ->and($sal['invoice_amount'])->toBe('27000.00')
        ->and($sal['net_billable_amount'])->toBe('25500.00')
        ->and($sal['is_final'])->toBeFalse();
});

test('a later SAL bills the delta against the previous cumulative value', function (): void {
    $sal = SalCalculator::compute('100000', '55', '30', '30000.00', '10000', '3000.00', '5');

    expect($sal['cumulative_gross_earned_value'])->toBe('55000.00')
        ->and($sal['current_period_gross_sal'])->toBe('25000.00')
        ->and($sal['advance_deduction_amount'])->toBe('2500.00')
        ->and($sal['guarantee_retention_withheld'])->toBe('1250.00')
        ->and($sal['net_billable_amount'])->toBe('21250.00');
});

test('the final SAL recoups the whole remaining advance', function (): void {
    $sal = SalCalculator::compute('100000', '100', '90', '90000.00', '10000', '8000.00', '5');

    expect($sal['is_final'])->toBeTrue()
        ->and($sal['current_period_gross_sal'])->toBe('10000.00')
        ->and($sal['advance_deduction_amount'])->toBe('2000.00');
});

test('the advance recouped never exceeds the remaining advance nor the gross of the SAL', function (): void {
    $capped = SalCalculator::compute('100000', '10', '0', '0', '50000', '49000', '0');
    $big = SalCalculator::compute('100000', '10', '0', '0', '1000000', '0', '0');

    expect($capped['advance_deduction_amount'])->toBe('1000.00')
        ->and($big['advance_deduction_amount'])->toBe('10000.00')
        ->and($big['invoice_amount'])->toBe('0.00');
});

test('the 0.50 percent public procurement retention is computed on the gross', function (): void {
    $sal = SalCalculator::compute('200000', '25', '0', '0', '0', '0', '0.5');

    expect($sal['current_period_gross_sal'])->toBe('50000.00')
        ->and($sal['guarantee_retention_withheld'])->toBe('250.00')
        ->and($sal['net_billable_amount'])->toBe('49750.00');
});

test('a progress that does not advance, exceeds 100 percent or has no contract value is refused', function (): void {
    expect(fn () => SalCalculator::compute('100000', '30', '30', '30000', '0', '0', '0'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => SalCalculator::compute('100000', '101', '30', '30000', '0', '0', '0'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => SalCalculator::compute('0', '10', '0', '0', '0', '0', '0'))->toThrow(InvalidArgumentException::class);
});

test('the markup applies to recharged costs', function (): void {
    expect(TimeAndMaterialsCalculator::withMarkup('200.00', '15'))->toBe('230.00')
        ->and(TimeAndMaterialsCalculator::withMarkup('200.00', '0'))->toBe('200.00');
});

test('the cap cuts the lines at what is left and drops the rest', function (): void {
    $result = TimeAndMaterialsCalculator::applyCap(['a' => '400.00', 'b' => '300.00', 'c' => '200.00'], '1000.00', '500.00');

    expect($result['billed'])->toBe(['a' => '400.00', 'b' => '100.00', 'c' => '0.00'])
        ->and($result['capped_out'])->toBe('400.00');
});

test('without a cap everything is billed, and an exhausted cap bills nothing', function (): void {
    expect(TimeAndMaterialsCalculator::applyCap(['a' => '400.00'], null, '9999.00'))->toBe(['billed' => ['a' => '400.00'], 'capped_out' => '0.00'])
        ->and(TimeAndMaterialsCalculator::applyCap(['a' => '400.00'], '1000.00', '1000.00'))->toBe(['billed' => ['a' => '0.00'], 'capped_out' => '400.00']);
});
