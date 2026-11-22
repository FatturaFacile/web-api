<?php

declare(strict_types=1);

use Modules\AnalyticalAccounting\Domain\Services\StepDownApportionmentEngine;

/**
 * @return array<string, array{parent_id: string|null, is_active: bool, category: string}>
 */
function adCenters(): array
{
    $make = static fn (string $category, ?string $parent = null): array => ['parent_id' => $parent, 'is_active' => true, 'category' => $category];

    return ['IT' => $make('AUXILIARY'), 'HR' => $make('AUXILIARY'), 'ASM' => $make('PRODUCTION'), 'CNC' => $make('PRODUCTION'), 'PC1' => $make('PROFIT'), 'FAC' => $make('STRUCTURAL'), 'FAC1' => $make('STRUCTURAL', 'FAC')];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function adStep(int $order, string $source, string $driver, array $extra = []): array
{
    return ['step_order' => $order, 'source_center_id' => $source, 'driver_type' => $driver, 'include_descendants' => false, 'fixed_weights' => null, 'target_filter' => null, 'driver_values' => [], ...$extra];
}

it('cascades step by step and never flows back into a closed sender', function (): void {
    $steps = [
        adStep(2, 'IT', 'AREA_SQM', ['driver_values' => ['HR' => '100', 'ASM' => '300', 'CNC' => '100']]),
        adStep(1, 'HR', 'HEADCOUNT_FTE', ['driver_values' => ['IT' => '2', 'ASM' => '5', 'CNC' => '3']]),
    ];

    $result = (new StepDownApportionmentEngine)->run($steps, ['IT' => 100000, 'HR' => 50000], adCenters());

    expect($result['steps'][0]['pool_cents'])->toBe(50000)
        ->and($result['steps'][1]['pool_cents'])->toBe(110000)
        ->and($result['final_balances']['ASM'])->toBe(107500)
        ->and($result['final_balances']['CNC'])->toBe(42500)
        ->and($result['final_balances']['IT'])->toBe(0)
        ->and($result['final_balances']['HR'])->toBe(0)
        ->and($result['total_allocated_cents'])->toBe(160000)
        ->and(collect($result['allocations'])->where('target_center_id', 'HR'))->toHaveCount(0)
        ->and(collect($result['warnings'])->pluck('code')->all())->toContain('CLOSED_RECEIVER_EXCLUDED');
});

it('keeps the grand total constant across the cascade', function (): void {
    $steps = [
        adStep(1, 'HR', 'HEADCOUNT_FTE', ['driver_values' => ['IT' => '2', 'ASM' => '5', 'CNC' => '3']]),
        adStep(2, 'IT', 'MACHINE_HOURS', ['driver_values' => ['ASM' => '7', 'CNC' => '3', 'PC1' => '1']]),
    ];

    $result = (new StepDownApportionmentEngine)->run($steps, ['IT' => 100000, 'HR' => 50000], adCenters());

    expect(array_sum($result['final_balances']))->toBe(150000);
});

it('allocates fixed percentages with the largest remainder so the step adds up exactly', function (): void {
    $steps = [adStep(1, 'IT', 'FIXED_PERCENTAGE', ['fixed_weights' => ['ASM' => '33.3333', 'CNC' => '33.3333', 'PC1' => '33.3334']])];

    $result = (new StepDownApportionmentEngine)->run($steps, ['IT' => 100000], adCenters());
    $byTarget = collect($result['allocations'])->pluck('allocated_cents', 'target_center_id')->all();

    expect($byTarget)->toBe(['ASM' => 33333, 'CNC' => 33333, 'PC1' => 33334])
        ->and(array_sum($byTarget))->toBe(100000)
        ->and($result['unallocated_cents'])->toBe(0);
});

it('rejects fixed percentages that do not add up to one hundred', function (): void {
    $steps = [adStep(1, 'IT', 'FIXED_PERCENTAGE', ['fixed_weights' => ['ASM' => '50', 'CNC' => '40']])];

    expect(fn () => (new StepDownApportionmentEngine)->run($steps, ['IT' => 1000], adCenters()))->toThrow(InvalidArgumentException::class, '100.0000');
});

it('rejects a fixed percentage aimed at an already closed center', function (): void {
    $steps = [
        adStep(1, 'HR', 'HEADCOUNT_FTE', ['driver_values' => ['IT' => '1']]),
        adStep(2, 'IT', 'FIXED_PERCENTAGE', ['fixed_weights' => ['HR' => '100']]),
    ];

    expect(fn () => (new StepDownApportionmentEngine)->run($steps, ['IT' => 1000, 'HR' => 1000], adCenters()))->toThrow(InvalidArgumentException::class, 'closed');
});

it('rejects a center that sends in two steps or a duplicated order', function (): void {
    $twice = [adStep(1, 'IT', 'AREA_SQM', ['driver_values' => ['ASM' => '1']]), adStep(2, 'IT', 'AREA_SQM', ['driver_values' => ['ASM' => '1']])];
    $sameOrder = [adStep(1, 'IT', 'AREA_SQM'), adStep(1, 'HR', 'AREA_SQM')];

    expect(fn () => (new StepDownApportionmentEngine)->run($twice, ['IT' => 10], adCenters()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new StepDownApportionmentEngine)->run($sameOrder, [], adCenters()))->toThrow(InvalidArgumentException::class, 'Duplicate step order');
});

it('keeps the pool unallocated and warns when the driver total is zero', function (): void {
    $steps = [adStep(1, 'IT', 'AREA_SQM', ['driver_values' => ['ASM' => '0']])];

    $result = (new StepDownApportionmentEngine)->run($steps, ['IT' => 70000], adCenters());

    expect($result['steps'][0]['status'])->toBe('SKIPPED')
        ->and($result['unallocated_cents'])->toBe(70000)
        ->and($result['allocations'])->toBe([])
        ->and($result['warnings'][0]['code'])->toBe('ZERO_DRIVER_TOTAL');
});

it('warns about an empty pool without allocating', function (): void {
    $result = (new StepDownApportionmentEngine)->run([adStep(1, 'IT', 'AREA_SQM', ['driver_values' => ['ASM' => '1']])], [], adCenters());

    expect($result['steps'][0]['status'])->toBe('SKIPPED')->and($result['warnings'][0]['code'])->toBe('EMPTY_POOL');
});

it('records the full trace of every allocation', function (): void {
    $steps = [adStep(1, 'IT', 'REVENUE_VOLUME', ['driver_values' => ['ASM' => '300', 'PC1' => '100']])];

    $allocation = (new StepDownApportionmentEngine)->run($steps, ['IT' => 10001], adCenters())['allocations'][0];

    expect($allocation)->toMatchArray(['step_order' => 1, 'source_center_id' => 'IT', 'target_center_id' => 'ASM', 'driver_type' => 'REVENUE_VOLUME', 'driver_value' => '300.0000', 'driver_total' => '400.00000000', 'share_ratio' => '0.75000000', 'allocated_cents' => 7501]);
});

it('apportions the cost of the descendants together with the sender and closes them', function (): void {
    $steps = [adStep(1, 'FAC', 'AREA_SQM', ['include_descendants' => true, 'driver_values' => ['FAC1' => '10', 'ASM' => '10']])];

    $result = (new StepDownApportionmentEngine)->run($steps, ['FAC' => 10000, 'FAC1' => 5000], adCenters());

    expect($result['steps'][0]['pool_cents'])->toBe(15000)
        ->and($result['final_balances']['ASM'])->toBe(15000)
        ->and(collect($result['allocations'])->where('target_center_id', 'FAC1'))->toHaveCount(0);
});

it('limits the receivers with the target filter', function (): void {
    $steps = [adStep(1, 'IT', 'AREA_SQM', ['driver_values' => ['ASM' => '1', 'CNC' => '1', 'PC1' => '2'], 'target_filter' => ['categories' => ['PRODUCTION']]])];

    $result = (new StepDownApportionmentEngine)->run($steps, ['IT' => 1001], adCenters());

    expect(collect($result['allocations'])->pluck('target_center_id')->all())->toBe(['ASM', 'CNC'])
        ->and($result['total_allocated_cents'])->toBe(1001);
});

it('splits a negative pool with the same exactness', function (): void {
    $steps = [adStep(1, 'IT', 'AREA_SQM', ['driver_values' => ['ASM' => '1', 'CNC' => '1', 'PC1' => '1']])];

    $result = (new StepDownApportionmentEngine)->run($steps, ['IT' => -100], adCenters());

    expect(collect($result['allocations'])->pluck('allocated_cents')->all())->toBe([-34, -33, -33]);
});
