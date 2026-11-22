<?php

declare(strict_types=1);

use Modules\Projects\Support\CostSourceType;
use Modules\Projects\Support\EvmCalculator;

test('the PMI formulas give EV, CV, SV, CPI, SPI, EAC, ETC and VAC', function (): void {
    $figures = EvmCalculator::compute('100000', '0.25', '0.5', '30000');

    expect($figures['earned_value'])->toBe('25000.0000')
        ->and($figures['planned_value'])->toBe('50000.0000')
        ->and($figures['cost_variance'])->toBe('-5000.0000')
        ->and($figures['schedule_variance'])->toBe('-25000.0000')
        ->and($figures['cost_performance_index'])->toBe('0.8333')
        ->and($figures['schedule_performance_index'])->toBe('0.5000')
        ->and($figures['estimate_at_completion'])->toBe('120000.0000')
        ->and($figures['estimate_to_complete'])->toBe('90000.0000')
        ->and($figures['variance_at_completion'])->toBe('-20000.0000')
        ->and($figures['budget_absorption_percent'])->toBe('30.00')
        ->and($figures['manual_etc_override'])->toBeNull();
});

test('an undefined index is null and the forecast falls back to the larger of BAC and AC', function (): void {
    $noCost = EvmCalculator::compute('100000', '0', '0', '0');

    expect($noCost['cost_performance_index'])->toBeNull()
        ->and($noCost['schedule_performance_index'])->toBeNull()
        ->and($noCost['estimate_at_completion'])->toBe('100000.0000')
        ->and($noCost['estimate_to_complete'])->toBe('100000.0000')
        ->and($noCost['variance_at_completion'])->toBe('0.0000');

    $costWithoutProgress = EvmCalculator::compute('100000', '0', '0.1', '120000');

    expect($costWithoutProgress['cost_performance_index'])->toBe('0.0000')
        ->and($costWithoutProgress['estimate_at_completion'])->toBe('120000.0000')
        ->and($costWithoutProgress['estimate_to_complete'])->toBe('0.0000')
        ->and($costWithoutProgress['variance_at_completion'])->toBe('-20000.0000');
});

test('a zero budget never divides by zero', function (): void {
    $figures = EvmCalculator::compute('0', '0.5', '0.5', '500');

    expect($figures['budget_absorption_percent'])->toBeNull()
        ->and($figures['earned_value'])->toBe('0.0000')
        ->and($figures['cost_performance_index'])->toBe('0.0000')
        ->and($figures['estimate_at_completion'])->toBe('500.0000');
});

test('progress is clamped between 0 and 1', function (): void {
    expect(EvmCalculator::compute('1000', '1.7', '-1', '500')['earned_value'])->toBe('1000.0000')
        ->and(EvmCalculator::compute('1000', '1.7', '-1', '500')['planned_value'])->toBe('0.0000');
});

test('a manual bottom-up ETC makes the EAC AC plus ETC and can be dropped again', function (): void {
    $manual = EvmCalculator::compute('100000', '0.25', '0.5', '30000', '60000');

    expect($manual['estimate_at_completion'])->toBe('90000.0000')
        ->and($manual['estimate_to_complete'])->toBe('60000.0000')
        ->and($manual['variance_at_completion'])->toBe('10000.0000')
        ->and($manual['manual_etc_override'])->toBe('60000.0000');

    expect(EvmCalculator::compute('100000', '0.25', '0.5', '30000', null)['estimate_at_completion'])->toBe('120000.0000');
});

test('the margin is revenue minus cost and its percentage needs revenue', function (): void {
    expect(EvmCalculator::margin('80000', '60000'))->toBe(['gross_margin_amount' => '20000.0000', 'gross_margin_percent' => '25.00'])
        ->and(EvmCalculator::margin('0', '500'))->toBe(['gross_margin_amount' => '-500.0000', 'gross_margin_percent' => null]);
});

test('thresholds breach below 0.90 for the indices and above 80 percent for the absorption', function (): void {
    expect(EvmCalculator::breaches('0.90', '1.20', '80.00'))->toBe([])
        ->and(EvmCalculator::breaches(null, null, null))->toBe([]);

    $breaches = EvmCalculator::breaches('0.7000', '0.8999', '100.00');

    expect(array_column($breaches, 'metric'))->toBe(['cpi', 'spi', 'absorption'])
        ->and(array_column($breaches, 'severity'))->toBe(['critical', 'warning', 'critical']);
});

test('the traffic light is green from 1.00, amber from 0.90 and red below', function (): void {
    expect(EvmCalculator::trafficLight('1.0000'))->toBe('green')
        ->and(EvmCalculator::trafficLight('0.9500'))->toBe('amber')
        ->and(EvmCalculator::trafficLight('0.9000'))->toBe('amber')
        ->and(EvmCalculator::trafficLight('0.8999'))->toBe('red')
        ->and(EvmCalculator::trafficLight(null))->toBe('green');
});

test('a job-cost category maps to the ledger source', function (): void {
    expect(CostSourceType::fromCostCategory('labour'))->toBe(CostSourceType::TimesheetLabor)
        ->and(CostSourceType::fromCostCategory('expense'))->toBe(CostSourceType::EmployeeExpense)
        ->and(CostSourceType::fromCostCategory('material'))->toBe(CostSourceType::WarehouseMaterialIssue)
        ->and(CostSourceType::fromCostCategory('supplier'))->toBe(CostSourceType::VendorInvoice)
        ->and(CostSourceType::fromCostCategory('fleet'))->toBe(CostSourceType::VendorInvoice);
});
