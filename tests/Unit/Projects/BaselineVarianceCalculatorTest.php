<?php

declare(strict_types=1);

use Modules\Projects\Wbs\BaselineVarianceCalculator;

/**
 * @param  array<string, mixed>  $values
 * @return array<string, mixed>
 */
function varianceRow(string $id, string $code, array $values = []): array
{
    return [
        'id' => $id, 'wbs_code' => $code, 'title' => "Node {$id}", 'node_type' => 'work_package',
        'start_date' => '2027-01-04', 'end_date' => '2027-01-08',
        'planned_hours' => '40.00', 'planned_cost' => '1000.00', 'planned_revenue' => '1500.00', 'percent_complete' => '0.00',
        ...$values,
    ];
}

test('variance is measured per node in days, hours, cost and revenue, and flags added and removed nodes', function (): void {
    $baseline = [varianceRow('a', '1'), varianceRow('b', '2'), varianceRow('gone', '3')];
    $current = [
        varianceRow('a', '1', ['end_date' => '2027-01-13', 'planned_hours' => '60.00', 'planned_cost' => '900.00']),
        varianceRow('b', '2'),
        varianceRow('new', '3', ['planned_hours' => '8.00', 'planned_cost' => '100.00', 'planned_revenue' => '120.00']),
    ];

    $result = (new BaselineVarianceCalculator)->compare($baseline, $current);
    $rows = collect($result['nodes'])->keyBy('node_id');

    expect($rows['a'])->toMatchArray([
        'change' => 'changed', 'start_variance_days' => 0, 'finish_variance_days' => 5,
        'planned_hours_variance' => '20.00', 'planned_cost_variance' => '-100.00', 'planned_revenue_variance' => '0.00',
    ])->and($rows['b']['change'])->toBe('unchanged')
        ->and($rows['new'])->toMatchArray(['change' => 'added', 'planned_hours_variance' => '8.00', 'baseline_planned_hours' => '0.00'])
        ->and($rows['gone'])->toMatchArray(['change' => 'removed', 'planned_hours_variance' => '-40.00', 'planned_cost' => '0.00']);
});

test('the summary compares the finish date and the totals of the top-level nodes only', function (): void {
    $baseline = [varianceRow('p', '1', ['planned_hours' => '40.00']), varianceRow('c', '1.1', ['planned_hours' => '40.00'])];
    $current = [varianceRow('p', '1', ['planned_hours' => '55.00', 'end_date' => '2027-01-20']), varianceRow('c', '1.1', ['planned_hours' => '55.00', 'end_date' => '2027-01-20'])];

    $summary = (new BaselineVarianceCalculator)->compare($baseline, $current)['summary'];

    expect($summary)->toMatchArray([
        'baseline_finish_date' => '2027-01-08', 'finish_date' => '2027-01-20', 'finish_variance_days' => 12,
        'baseline_planned_hours' => '40.00', 'planned_hours' => '55.00', 'planned_hours_variance' => '15.00',
    ]);
});

test('an earlier finish gives a negative variance', function (): void {
    $summary = (new BaselineVarianceCalculator)->compare([varianceRow('a', '1')], [varianceRow('a', '1', ['end_date' => '2027-01-06'])])['summary'];

    expect($summary['finish_variance_days'])->toBe(-2);
});

test('nodes without dates have no schedule variance', function (): void {
    $row = varianceRow('a', '1', ['start_date' => null, 'end_date' => null]);
    $result = (new BaselineVarianceCalculator)->compare([$row], [$row]);

    expect($result['nodes'][0])->toMatchArray(['change' => 'unchanged', 'start_variance_days' => null, 'finish_variance_days' => null]);
});
