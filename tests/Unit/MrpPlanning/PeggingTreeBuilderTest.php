<?php

declare(strict_types=1);

use Modules\MrpPlanning\Domain\Services\PeggingTreeBuilder;
use Modules\MrpPlanning\Domain\ValueObjects\BomLine;
use Modules\MrpPlanning\Domain\ValueObjects\PlannedProposal;

require_once __DIR__.'/MrpTestSupport.php';

/**
 * The persisted shape of the proposals of the multi-level engine scenario.
 *
 * @param  list<PlannedProposal>  $proposals
 * @return array<string, array<string, mixed>>
 */
function peggingNodes(array $proposals): array
{
    $nodes = [];
    foreach ($proposals as $proposal) {
        $nodes[$proposal->id] = [
            'id' => $proposal->id, 'item_id' => $proposal->itemId, 'item_code' => strtoupper($proposal->itemId), 'item_name' => $proposal->itemId,
            'proposal_type' => $proposal->type->value, 'status' => 'Proposed', 'quantity' => $proposal->suggestedQty,
            'need_by_date' => $proposal->needBy->toDateString(), 'pegging' => $proposal->pegging,
        ];
    }

    return $nodes;
}

it('traces a component back to the sales order through its parents and forward through its own components', function (): void {
    $result = mrpRun([
        mrpItem('fg', ['procurement' => 'make', 'lead' => 3, 'components' => [new BomLine('sub', '2')]]),
        mrpItem('sub', ['procurement' => 'make', 'lead' => 2, 'components' => [new BomLine('raw', '3')]]),
        mrpItem('raw'),
    ], [mrpDemand('fg', '2027-03-26', '10', 'so-77')]);

    $nodes = peggingNodes($result->proposals);
    [$fg] = mrpOf($result, 'fg');
    [$sub] = mrpOf($result, 'sub');
    [$raw] = mrpOf($result, 'raw');
    $builder = new PeggingTreeBuilder;

    $tree = $builder->build($sub->id, $nodes);

    expect($tree['proposal']['id'])->toBe($sub->id)
        // upstream: sub is pegged to fg's proposal, which is pegged to the sales order line
        ->and($tree['upstream'])->toHaveCount(1)
        ->and($tree['upstream'][0]['id'])->toBe($fg->id)
        ->and($tree['upstream'][0]['proposal']['item_code'])->toBe('FG')
        ->and($tree['upstream'][0]['upstream'])->toBe([['type' => 'sales_order_line', 'id' => 'so-77', 'quantity' => '10.0000', 'proposal' => null, 'upstream' => []]])
        ->and($tree['root_demands'])->toBe([['type' => 'sales_order_line', 'id' => 'so-77', 'quantity' => '10.0000']])
        // downstream: the raw material proposal that sub's explosion created
        ->and($tree['downstream'])->toHaveCount(1)
        ->and($tree['downstream'][0]['id'])->toBe($raw->id)
        ->and($tree['downstream'][0]['children'])->toBe([]);

    $root = $builder->build($fg->id, $nodes);
    expect($root['upstream'][0]['type'])->toBe('sales_order_line')
        ->and($root['downstream'][0]['id'])->toBe($sub->id)
        ->and($root['downstream'][0]['children'][0]['id'])->toBe($raw->id);
});

it('returns null for an unknown proposal and survives a pegging loop', function (): void {
    $nodes = [
        'a' => ['id' => 'a', 'item_id' => 'x', 'item_code' => 'X', 'item_name' => 'x', 'proposal_type' => 'PlannedProductionOrder', 'status' => 'Proposed', 'quantity' => '1', 'need_by_date' => null, 'pegging' => [['type' => 'mrp_proposal', 'id' => 'b', 'quantity' => '1']]],
        'b' => ['id' => 'b', 'item_id' => 'y', 'item_code' => 'Y', 'item_name' => 'y', 'proposal_type' => 'PlannedProductionOrder', 'status' => 'Proposed', 'quantity' => '1', 'need_by_date' => null, 'pegging' => [['type' => 'mrp_proposal', 'id' => 'a', 'quantity' => '1']]],
    ];
    $builder = new PeggingTreeBuilder;

    expect($builder->build('missing', $nodes))->toBeNull()
        ->and($builder->build('a', $nodes)['upstream'][0]['id'])->toBe('b');
});

it('adds up root demands that reach a proposal through several parents', function (): void {
    $result = mrpRun([
        mrpItem('a', ['procurement' => 'make', 'lead' => 1, 'components' => [new BomLine('bolt', '4')]]),
        mrpItem('b', ['procurement' => 'make', 'lead' => 1, 'components' => [new BomLine('bolt', '6')]]),
        mrpItem('bolt'),
    ], [mrpDemand('a', '2027-03-19', '10', 'so-a'), mrpDemand('b', '2027-03-19', '10', 'so-a')]);

    [$bolt] = mrpOf($result, 'bolt');
    $tree = (new PeggingTreeBuilder)->build($bolt->id, peggingNodes($result->proposals));

    expect($tree['upstream'])->toHaveCount(2)->and($tree['root_demands'])->toBe([['type' => 'sales_order_line', 'id' => 'so-a', 'quantity' => '20.0000']]);
});
