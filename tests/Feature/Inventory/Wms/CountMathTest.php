<?php

declare(strict_types=1);

use Modules\Inventory\Models\InventoryCountPolicy;
use Modules\Inventory\Services\AbcParetoClassifier;
use Modules\Inventory\Services\CountVarianceMath;

/**
 * The arithmetic of physical counting, without database: the Pareto classification (80/15/5 defaults, custom shares,
 * zero consumption, ties) and the variance percentage, recount trigger and approval level.
 */
function paretoEntries(array $values): array
{
    $entries = [];

    foreach ($values as $id => $value) {
        $entries[] = ['id' => (string) $id, 'value' => (string) $value];
    }

    return $entries;
}

function paretoClasses(array $rows): array
{
    $classes = [];

    foreach ($rows as $row) {
        $classes[$row['id']] = $row['abc_class'];
    }

    return $classes;
}

function policyWith(array $overrides): InventoryCountPolicy
{
    return (new InventoryCountPolicy)->forceFill($overrides + InventoryCountPolicy::DEFAULTS);
}

test('the default 80/95 Pareto split ranks by value and assigns A, B and C by cumulative share', function (): void {
    // Total 1000: 500 (50%), 300 (80%) -> A; 150 (95%) -> B; 50 (100%) -> C.
    $rows = AbcParetoClassifier::classify(paretoEntries(['a' => 500, 'b' => 300, 'c' => 150, 'd' => 50]));

    expect(paretoClasses($rows))->toBe(['a' => 'A', 'b' => 'A', 'c' => 'B', 'd' => 'C'])
        ->and($rows[0]['rank'])->toBe(1)
        ->and($rows[1]['cumulative_share'])->toBe('80.00')
        ->and($rows[3]['cumulative_share'])->toBe('100.00');
});

test('custom shares move the class boundaries', function (): void {
    $rows = AbcParetoClassifier::classify(paretoEntries(['a' => 500, 'b' => 300, 'c' => 150, 'd' => 50]), '50', '80');

    expect(paretoClasses($rows))->toBe(['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'C']);
});

test('items without consumption are class C and a zero total makes everything C', function (): void {
    expect(paretoClasses(AbcParetoClassifier::classify(paretoEntries(['a' => 100, 'idle' => 0]))))->toBe(['a' => 'A', 'idle' => 'C'])
        ->and(paretoClasses(AbcParetoClassifier::classify(paretoEntries(['x' => 0, 'y' => 0]))))->toBe(['x' => 'C', 'y' => 'C']);
});

test('a single dominant item is class A even when it alone crosses the A share', function (): void {
    $rows = AbcParetoClassifier::classify(paretoEntries(['big' => 900, 'small' => 100]));

    expect(paretoClasses($rows))->toBe(['big' => 'A', 'small' => 'C']);
});

test('items with the same value share one class whatever their ids', function (): void {
    // 40 + 40 + 40 + 40 + 40 = 200: the group of five ends at 100%, so none is split from the others.
    $rows = AbcParetoClassifier::classify(paretoEntries(['e' => 40, 'd' => 40, 'c' => 40, 'b' => 40, 'a' => 40]));

    expect(array_unique(array_values(paretoClasses($rows))))->toHaveCount(1)
        ->and(array_column($rows, 'id'))->toBe(['a', 'b', 'c', 'd', 'e']);
});

test('the variance percentage is relative to the system quantity and undefined when nothing was expected', function (): void {
    expect(CountVarianceMath::percentage('10', '8'))->toBe('20.00')
        ->and(CountVarianceMath::percentage('10', '12.5'))->toBe('25.00')
        ->and(CountVarianceMath::percentage('10', '10'))->toBe('0.00')
        ->and(CountVarianceMath::percentage('0', '0'))->toBe('0.00')
        ->and(CountVarianceMath::percentage('0', '3'))->toBeNull();
});

test('a recount is due above the percentage or value threshold until the rounds are used up', function (): void {
    $policy = policyWith(['recount_threshold_percent' => '10.00', 'recount_threshold_value' => '100.00', 'max_recount_rounds' => 2]);

    expect(CountVarianceMath::recountDue($policy, '5.00', '15.00', false, 0))->toBeTrue()
        ->and(CountVarianceMath::recountDue($policy, '500.00', '1.00', false, 0))->toBeTrue()
        ->and(CountVarianceMath::recountDue($policy, '5.00', '10.00', false, 0))->toBeFalse()
        ->and(CountVarianceMath::recountDue($policy, '5.00', null, true, 0))->toBeTrue()
        ->and(CountVarianceMath::recountDue($policy, '5.00', '90.00', false, 2))->toBeFalse()
        ->and(CountVarianceMath::recountDue(policyWith([]), '5000.00', '90.00', false, 0))->toBeFalse();
});

test('the approval level follows the level 1 and level 2 thresholds, by percentage and by value', function (): void {
    $policy = policyWith([
        'approval_mode' => 'multi_level',
        'level1_threshold_percent' => '5.00', 'level1_threshold_value' => '100.00',
        'level2_threshold_percent' => '20.00', 'level2_threshold_value' => '1000.00',
    ]);
    $line = static fn (string $value, ?string $percentage): array => ['variance_value' => $value, 'variance_percentage' => $percentage, 'variance_quantity' => '1'];

    expect(CountVarianceMath::approvalLevel($policy, []))->toBe(0)
        ->and(CountVarianceMath::approvalLevel($policy, [$line('-50.00', '4.00')]))->toBe(0)
        ->and(CountVarianceMath::approvalLevel($policy, [$line('-50.00', '6.00')]))->toBe(1)
        ->and(CountVarianceMath::approvalLevel($policy, [$line('150.00', '1.00')]))->toBe(1)
        ->and(CountVarianceMath::approvalLevel($policy, [$line('-50.00', '25.00')]))->toBe(2)
        ->and(CountVarianceMath::approvalLevel($policy, [$line('-600.00', '1.00'), $line('600.00', '1.00')]))->toBe(2)
        ->and(CountVarianceMath::approvalLevel($policy, [$line('10.00', null)]))->toBe(2);
});

test('without thresholds a count is within tolerance', function (): void {
    expect(CountVarianceMath::approvalLevel(policyWith(['approval_mode' => 'multi_level']), [['variance_value' => '99999.00', 'variance_percentage' => '90.00', 'variance_quantity' => '5']]))->toBe(0);
});
