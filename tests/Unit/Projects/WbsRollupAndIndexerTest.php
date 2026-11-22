<?php

declare(strict_types=1);

use Modules\Projects\Wbs\WbsCodeIndexer;
use Modules\Projects\Wbs\WbsRollupCalculator;

/**
 * @param  array<string, mixed>  $values
 * @return array<string, mixed>
 */
function rollupNode(string $id, ?string $parent, array $values = []): array
{
    return ['id' => $id, 'parent_id' => $parent, ...$values];
}

test('values roll up bottom-up through any depth and typed values on a summary node are ignored', function (): void {
    $result = (new WbsRollupCalculator)->rollUp([
        // The phase and the deliverable carry typed values that must not be counted: they have children.
        rollupNode('phase', null, ['planned_hours' => 999, 'planned_cost' => 999, 'actual_hours' => 999]),
        rollupNode('wp1', 'phase', ['planned_hours' => 10, 'planned_cost' => 100, 'actual_hours' => 4, 'actual_cost' => 40, 'planned_revenue' => 150, 'percent_complete' => 50, 'start_date' => '2027-01-11', 'end_date' => '2027-01-15']),
        rollupNode('deliverable', 'phase', ['planned_hours' => 500]),
        rollupNode('wp2', 'deliverable', ['planned_hours' => 30, 'planned_cost' => 300, 'actual_hours' => 30, 'actual_cost' => 310, 'planned_revenue' => 450, 'percent_complete' => 100, 'start_date' => '2027-01-04', 'end_date' => '2027-01-08']),
        rollupNode('wp3', 'deliverable', ['planned_hours' => 10, 'planned_cost' => 100, 'percent_complete' => 0, 'start_date' => '2027-01-18', 'end_date' => '2027-01-22']),
    ]);

    expect($result['nodes']['deliverable'])->toMatchArray([
        'planned_hours' => '40.00', 'planned_cost' => '400.00', 'actual_hours' => '30.00', 'actual_cost' => '310.00',
        'planned_revenue' => '450.00', 'percent_complete' => '75.00', 'start_date' => '2027-01-04', 'end_date' => '2027-01-22', 'is_leaf' => false,
    ])->and($result['nodes']['phase'])->toMatchArray([
        'planned_hours' => '50.00', 'planned_cost' => '500.00', 'actual_hours' => '34.00', 'actual_cost' => '350.00',
        'planned_revenue' => '600.00', 'percent_complete' => '70.00', 'start_date' => '2027-01-04', 'end_date' => '2027-01-22',
    ])->and($result['nodes']['wp1'])->toMatchArray(['planned_hours' => '10.00', 'is_leaf' => true])
        ->and($result['totals'])->toMatchArray(['planned_hours' => '50.00', 'planned_cost' => '500.00', 'percent_complete' => '70.00']);
});

test('several top-level nodes add up in the totals without double counting', function (): void {
    $result = (new WbsRollupCalculator)->rollUp([
        rollupNode('p1', null),
        rollupNode('a', 'p1', ['planned_hours' => 8, 'planned_revenue' => 100]),
        rollupNode('p2', null, ['planned_hours' => 12, 'planned_revenue' => 200]),
    ]);

    expect($result['totals'])->toMatchArray(['planned_hours' => '20.00', 'planned_revenue' => '300.00']);
});

test('progress falls back to cost weights, then to a plain average, when there are no hours', function (): void {
    $byCost = (new WbsRollupCalculator)->rollUp([
        rollupNode('p', null),
        rollupNode('a', 'p', ['planned_cost' => 100, 'percent_complete' => 100]),
        rollupNode('b', 'p', ['planned_cost' => 300, 'percent_complete' => 0]),
    ]);
    $byAverage = (new WbsRollupCalculator)->rollUp([
        rollupNode('p', null),
        rollupNode('a', 'p', ['percent_complete' => 20]),
        rollupNode('b', 'p', ['percent_complete' => 60]),
    ]);

    expect($byCost['nodes']['p']['percent_complete'])->toBe('25.00')
        ->and($byAverage['nodes']['p']['percent_complete'])->toBe('40.00');
});

test('progress of a leaf is kept between 0 and 100', function (): void {
    $result = (new WbsRollupCalculator)->rollUp([rollupNode('a', null, ['percent_complete' => 140]), rollupNode('b', null, ['percent_complete' => -5])]);

    expect($result['nodes']['a']['percent_complete'])->toBe('100.00')->and($result['nodes']['b']['percent_complete'])->toBe('0.00');
});

test('a very deep tree rolls up to the top', function (): void {
    $nodes = [];
    $parent = null;

    for ($level = 0; $level < 60; $level++) {
        $nodes[] = rollupNode("n{$level}", $parent);
        $parent = "n{$level}";
    }

    $nodes[] = rollupNode('leaf', $parent, ['planned_hours' => 7]);

    expect((new WbsRollupCalculator)->rollUp($nodes)['nodes']['n0']['planned_hours'])->toBe('7.00');
});

test('an empty WBS has zero totals', function (): void {
    expect((new WbsRollupCalculator)->rollUp([])['totals'])->toMatchArray(['planned_hours' => '0.00', 'percent_complete' => '0.00']);
});

test('WBS codes are numbered per level in sort order, at any depth', function (): void {
    $codes = (new WbsCodeIndexer)->index([
        ['id' => 'b', 'parent_id' => null, 'sort_order' => 5],
        ['id' => 'a', 'parent_id' => null, 'sort_order' => 2],
        ['id' => 'a2', 'parent_id' => 'a', 'sort_order' => 9],
        ['id' => 'a1', 'parent_id' => 'a', 'sort_order' => 3],
        ['id' => 'a1x', 'parent_id' => 'a1', 'sort_order' => 1],
    ]);

    expect($codes['a'])->toBe(['code' => '1', 'sort_order' => 1])
        ->and($codes['b'])->toBe(['code' => '2', 'sort_order' => 2])
        ->and($codes['a1']['code'])->toBe('1.1')
        ->and($codes['a2']['code'])->toBe('1.2')
        ->and($codes['a1x']['code'])->toBe('1.1.1');
});

test('removing or inserting a node renumbers its siblings without gaps', function (): void {
    $indexer = new WbsCodeIndexer;
    $after = $indexer->index([
        ['id' => 'x', 'parent_id' => null, 'sort_order' => 1],
        ['id' => 'z', 'parent_id' => null, 'sort_order' => 3],
    ]);
    $inserted = $indexer->index([
        ['id' => 'x', 'parent_id' => null, 'sort_order' => 1],
        ['id' => 'new', 'parent_id' => null, 'sort_order' => 1],
        ['id' => 'z', 'parent_id' => null, 'sort_order' => 3],
    ]);

    expect($after['z']['code'])->toBe('2')
        ->and($inserted['x']['code'])->toBe('1')
        ->and($inserted['new']['code'])->toBe('2')
        ->and($inserted['z']['code'])->toBe('3');
});

test('a node whose parent is unknown is treated as a top-level node', function (): void {
    $codes = (new WbsCodeIndexer)->index([['id' => 'orphan', 'parent_id' => 'ghost', 'sort_order' => 1]]);

    expect($codes['orphan']['code'])->toBe('1');
});
