<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\MrpPlanning\Domain\Enums\CapacitySchedulingMode;
use Modules\MrpPlanning\Domain\Enums\DemandSourceType;
use Modules\MrpPlanning\Domain\Enums\LotSizingRule;
use Modules\MrpPlanning\Domain\Enums\SchedulingDirection;
use Modules\MrpPlanning\Domain\Enums\SupplyType;
use Modules\MrpPlanning\Domain\Exceptions\BomCycleException;
use Modules\MrpPlanning\Domain\ValueObjects\BomLine;
use Modules\MrpPlanning\Domain\ValueObjects\ItemPlanningData;
use Modules\MrpPlanning\Domain\ValueObjects\RoutingOperation;
use Modules\MrpPlanning\Domain\ValueObjects\ScheduleBlock;
use Modules\MrpPlanning\Domain\ValueObjects\ShutdownPeriod;

require_once __DIR__.'/MrpTestSupport.php';

// ---------------------------------------------------------------- gross-to-net

it('nets gross requirements against on-hand, allocations, receipts and the safety stock floor', function (): void {
    // 30 on hand - 10 allocated = 20; +8 received on 03-10 = 28; -50 demand on Friday 03-19 = -22; safety floor 5 -> net 27.
    $result = mrpRun(
        [mrpItem('part', ['on_hand' => '30', 'allocated' => '10', 'safety' => '5'])],
        [mrpDemand('part', '2027-03-19', '50')],
        [mrpSupply('part', '2027-03-10', '8')],
    );

    [$order] = mrpOf($result, 'part', 'PlannedPurchaseRequisition');

    expect($order->grossQty)->toBe('50.0000')
        ->and($order->projectedOnHandQty)->toBe('-22.0000')
        ->and($order->netQty)->toBe('27.0000')
        ->and($order->requiredQty)->toBe('27.0000')
        ->and($order->suggestedQty)->toBe('27.0000')
        ->and($order->needBy->toDateString())->toBe('2027-03-19')
        ->and($order->pegging)->toBe([['type' => 'sales_order_line', 'id' => 'so-1', 'quantity' => '27.0000']]);
});

it('does not count the safety stock twice: stock above the floor plans nothing, stock below it plans only the gap', function (): void {
    expect(mrpRun([mrpItem('a', ['on_hand' => '20', 'safety' => '10'])], [])->proposals)->toBe([]);

    $result = mrpRun([mrpItem('b', ['on_hand' => '4', 'safety' => '10'])], []);
    $orders = mrpOf($result, 'b');

    expect($orders)->toHaveCount(1)
        ->and($orders[0]->netQty)->toBe('6.0000')
        ->and($orders[0]->exceptions)->toContain('BelowSafetyStock')
        ->and($orders[0]->pegging)->toBe([['type' => 'safety_stock', 'id' => 'b', 'quantity' => '6.0000']]);
});

it('keeps the floor after the planned receipt, so later demand does not re-add the safety stock', function (): void {
    // Safety 10, nothing on hand: today an order of 10 builds the floor; each demand of 5 then needs only 5, not 15.
    $result = mrpRun(
        [mrpItem('p', ['safety' => '10'])],
        [mrpDemand('p', '2027-03-10', '5', 'so-a'), mrpDemand('p', '2027-03-12', '5', 'so-b')],
    );

    $orders = mrpOf($result, 'p', 'PlannedPurchaseRequisition');

    expect(array_map(static fn ($o): string => $o->suggestedQty, $orders))->toBe(['10.0000', '5.0000', '5.0000']);
});

it('pegs a proposal to the MPS line and the sales order line first-come first-served', function (): void {
    $result = mrpRun(
        [mrpItem('p')],
        [mrpDemand('p', '2027-03-10', '6', 'mps-1', DemandSourceType::MpsLine), mrpDemand('p', '2027-03-10', '3', 'so-9')],
    );

    [$order] = mrpOf($result, 'p');

    expect($order->netQty)->toBe('9.0000')
        ->and($order->pegging)->toBe([
            ['type' => 'mps_line', 'id' => 'mps-1', 'quantity' => '6.0000'],
            ['type' => 'sales_order_line', 'id' => 'so-9', 'quantity' => '3.0000'],
        ]);
});

it('pegs stock that is over-allocated to the allocation and the shortfall against the floor to the safety stock', function (): void {
    // 2 on hand, 5 allocated elsewhere, safety 4: the projection starts at -3, the order restores the floor (7 = 3 + 4).
    [$order] = mrpOf(mrpRun([mrpItem('p', ['on_hand' => '2', 'allocated' => '5', 'safety' => '4'])], []), 'p');

    expect($order->netQty)->toBe('7.0000')
        ->and($order->pegging)->toBe([
            ['type' => 'safety_stock', 'id' => 'p', 'quantity' => '4.0000'],
            ['type' => 'allocated_stock', 'id' => 'p', 'quantity' => '3.0000'],
        ]);
});

it('ignores demand beyond the planning horizon and non-positive quantities', function (): void {
    $result = mrpRun([mrpItem('p')], [mrpDemand('p', '2027-12-01', '5'), mrpDemand('p', '2027-03-10', '0')], [], ['horizon' => 30]);

    expect($result->proposals)->toBe([]);
});

// ---------------------------------------------------------------- multi level

it('explodes multi-level demand in low-level-code order with scrap and pegs each level to its parent', function (): void {
    // fg (make, 3 days) -> 2 x sub (make, 2 days, 10 % scrap on the line) -> 3 x raw (buy, 5 days).
    $items = [
        mrpItem('fg', ['procurement' => 'make', 'lead' => 3, 'components' => [new BomLine('sub', '2', '10')]]),
        mrpItem('sub', ['procurement' => 'make', 'lead' => 2, 'components' => [new BomLine('raw', '3')]]),
        mrpItem('raw', ['lead' => 5]),
    ];
    $result = mrpRun($items, [mrpDemand('fg', '2027-03-26', '10')]);

    [$fg] = mrpOf($result, 'fg');
    [$sub] = mrpOf($result, 'sub');
    [$raw] = mrpOf($result, 'raw');

    expect($result->lowLevelCodes)->toBe(['fg' => 0, 'sub' => 1, 'raw' => 2])
        ->and($fg->type->value)->toBe('PlannedProductionOrder')
        ->and($fg->suggestedQty)->toBe('10.0000')
        ->and($fg->releaseBy->toDateString())->toBe('2027-03-23')
        // 10 fg x 2 sub x 1.10 scrap = 22 sub, needed on the fg release date.
        ->and($sub->suggestedQty)->toBe('22.0000')
        ->and($sub->needBy->toDateString())->toBe('2027-03-23')
        ->and($sub->pegging)->toBe([['type' => 'mrp_proposal', 'id' => $fg->id, 'quantity' => '22.0000']])
        ->and($sub->releaseBy->toDateString())->toBe('2027-03-19')
        ->and($raw->suggestedQty)->toBe('66.0000')
        ->and($raw->pegging)->toBe([['type' => 'mrp_proposal', 'id' => $sub->id, 'quantity' => '66.0000']])
        ->and([$fg->lowLevelCode, $sub->lowLevelCode, $raw->lowLevelCode])->toBe([0, 1, 2]);
});

it('nets a shared component once, after all of its parents', function (): void {
    $items = [
        mrpItem('a', ['procurement' => 'make', 'lead' => 1, 'components' => [new BomLine('bolt', '4')]]),
        mrpItem('b', ['procurement' => 'make', 'lead' => 1, 'components' => [new BomLine('bolt', '6')]]),
        mrpItem('bolt', ['on_hand' => '50', 'lead' => 3]),
    ];
    $result = mrpRun($items, [mrpDemand('a', '2027-03-19', '10', 'so-a'), mrpDemand('b', '2027-03-19', '10', 'so-b')]);

    [$bolt] = mrpOf($result, 'bolt', 'PlannedPurchaseRequisition');

    // gross 40 + 60 on the same day (the release date of both parents), 50 in stock -> net 50.
    expect($bolt->grossQty)->toBe('100.0000')->and($bolt->netQty)->toBe('50.0000')->and($bolt->pegging)->toHaveCount(2);
});

it('fails with the cycle path when the bills of materials loop', function (): void {
    mrpRun([
        mrpItem('a', ['procurement' => 'make', 'components' => [new BomLine('b', '1')]]),
        mrpItem('b', ['procurement' => 'make', 'components' => [new BomLine('a', '1')]]),
    ], [mrpDemand('a', '2027-03-19', '1')]);
})->throws(BomCycleException::class);

// ---------------------------------------------------------------- lot sizing in a run

it('applies the lot rule and the item scrap to the planned order and credits only the good output', function (): void {
    // Need 100 on 03-10 and 100 on 03-24, 3 % scrap, multiple of 50: each order starts 150 and yields 145.63 good.
    $item = mrpItem('p', ['scrap' => '3', 'rule' => LotSizingRule::OrderMultiple, 'multiple' => '50']);
    $result = mrpRun([$item], [mrpDemand('p', '2027-03-10', '100'), mrpDemand('p', '2027-03-24', '100')]);

    $orders = mrpOf($result, 'p');

    // 150 / 1.03 = 145.6311 good: it covers the first 100 and 45.6311 of the second, so the second order is still needed (54.3689 short -> 55.99 -> 100).
    expect($orders)->toHaveCount(2)
        ->and($orders[0]->requiredQty)->toBe('100.0000')
        ->and($orders[0]->suggestedQty)->toBe('150.0000')
        ->and($orders[1]->requiredQty)->toBe('54.3689')
        ->and($orders[1]->suggestedQty)->toBe('100.0000');
});

it('covers a fixed number of days of supply with one order', function (): void {
    $item = mrpItem('p', ['rule' => LotSizingRule::FixedDaysOfSupply, 'fixed_days' => 7]);
    $result = mrpRun([$item], [mrpDemand('p', '2027-03-10', '10', 'a'), mrpDemand('p', '2027-03-12', '20', 'b'), mrpDemand('p', '2027-03-16', '5', 'c'), mrpDemand('p', '2027-03-30', '8', 'd')]);

    $orders = mrpOf($result, 'p');

    // Window 03-10..03-16 holds 10 + 20 + 5 = 35 in one order; the demand of 03-30 starts the next one.
    expect(array_map(static fn ($o): string => $o->suggestedQty, $orders))->toBe(['35.0000', '8.0000']);
});

// ---------------------------------------------------------------- lead time, holidays, customs

it('releases an order its lead time before the need date and never on a weekend', function (): void {
    [$order] = mrpOf(mrpRun([mrpItem('p', ['lead' => 5])], [mrpDemand('p', '2027-03-19', '1')]), 'p');

    // 03-19 - 5 days = Sunday 03-14 -> Friday 03-12.
    expect($order->releaseBy->toDateString())->toBe('2027-03-12')->and($order->exceptions)->not->toContain('HolidayShiftApplied');
});

it('flags a release date in the past', function (): void {
    [$order] = mrpOf(mrpRun([mrpItem('p', ['lead' => 5])], [mrpDemand('p', '2027-03-03', '1')]), 'p');

    expect($order->releaseBy->toDateString())->toBe('2027-02-26')->and($order->exceptions)->toContain('PastDueRelease');
});

it('skips the supplier shutdown (Ferragosto) when counting lead time days and flags the shift', function (): void {
    $item = mrpItem('p', ['lead' => 10, 'supplier' => 'sup-it', 'country' => 'IT']);
    $ferragosto = [new ShutdownPeriod('sup-it', CarbonImmutable::parse('2027-08-10'), CarbonImmutable::parse('2027-08-20'), 'Ferragosto')];

    // Wednesday 08-25: without calendars 15 Aug (Sunday) -> Friday 08-13.
    [$plain] = mrpOf(mrpRun([$item], [mrpDemand('p', '2027-08-25', '1')], [], ['shutdown_periods' => $ferragosto]), 'p');
    // With them the 11 closed days do not count: 10 working lead days end on Wednesday 08-04.
    [$shifted] = mrpOf(mrpRun([$item], [mrpDemand('p', '2027-08-25', '1')], [], ['shutdown_periods' => $ferragosto, 'shutdowns' => true]), 'p');

    expect($plain->releaseBy->toDateString())->toBe('2027-08-13')->and($plain->exceptions)->not->toContain('HolidayShiftApplied')
        ->and($shifted->releaseBy->toDateString())->toBe('2027-08-04')->and($shifted->exceptions)->toContain('HolidayShiftApplied');
});

it('moves a release date that falls inside a shutdown to the last open day before it', function (): void {
    $item = mrpItem('p', ['lead' => 0, 'supplier' => 'sup-ch']);
    $closed = [new ShutdownPeriod('sup-ch', CarbonImmutable::parse('2027-07-20'), CarbonImmutable::parse('2027-08-09'), 'Betriebsferien')];

    [$order] = mrpOf(mrpRun([$item], [mrpDemand('p', '2027-08-05', '1')], [], ['shutdown_periods' => $closed, 'shutdowns' => true]), 'p');

    expect($order->releaseBy->toDateString())->toBe('2027-07-19')->and($order->exceptions)->toContain('HolidayShiftApplied');
});

it('applies a shutdown of all suppliers (supplier id null) to every item', function (): void {
    $closed = [new ShutdownPeriod(null, CarbonImmutable::parse('2027-12-24'), CarbonImmutable::parse('2028-01-02'), 'Winter')];

    [$order] = mrpOf(mrpRun([mrpItem('p', ['lead' => 0])], [mrpDemand('p', '2027-12-28', '1')], [], ['shutdown_periods' => $closed, 'shutdowns' => true, 'horizon' => 400]), 'p');

    expect($order->releaseBy->toDateString())->toBe('2027-12-23');
});

it('adds the customs clearance days to the lead time of a cross-border supplier only when asked to', function (): void {
    $item = mrpItem('p', ['lead' => 5, 'supplier' => 'sup-it', 'country' => 'IT', 'customs' => 3]);

    [$without] = mrpOf(mrpRun([$item], [mrpDemand('p', '2027-03-26', '1')]), 'p');
    [$with] = mrpOf(mrpRun([$item], [mrpDemand('p', '2027-03-26', '1')], [], ['customs' => true]), 'p');
    [$domestic] = mrpOf(mrpRun([mrpItem('p', ['lead' => 5, 'country' => 'CH', 'customs' => 3])], [mrpDemand('p', '2027-03-26', '1')], [], ['customs' => true]), 'p');
    [$euToEu] = mrpOf(mrpRun([$item], [mrpDemand('p', '2027-03-26', '1')], [], ['customs' => true, 'country' => 'DE']), 'p');

    // Customs 03-23 .. 03-26, then 5 lead days back from the 23rd = Thursday 03-18.
    expect($without->releaseBy->toDateString())->toBe('2027-03-19')
        ->and($with->releaseBy->toDateString())->toBe('2027-03-18')->and($with->exceptions)->toContain('CustomsLeadTimeApplied')
        ->and($domestic->exceptions)->not->toContain('CustomsLeadTimeApplied')
        ->and($euToEu->exceptions)->not->toContain('CustomsLeadTimeApplied');
});

it('plans forward from today and reports a delivery after the need date', function (): void {
    $params = ['direction' => SchedulingDirection::ForwardFromToday];

    [$early] = mrpOf(mrpRun([mrpItem('p', ['lead' => 5])], [mrpDemand('p', '2027-03-19', '1')], [], $params), 'p');
    [$late] = mrpOf(mrpRun([mrpItem('p', ['lead' => 30])], [mrpDemand('p', '2027-03-19', '1')], [], $params), 'p');

    expect($early->plannedStart->toDateString())->toBe('2027-03-01')->and($early->plannedEnd->toDateString())->toBe('2027-03-06')->and($early->exceptions)->not->toContain('LateDelivery')
        ->and($late->plannedEnd->toDateString())->toBe('2027-03-31')->and($late->exceptions)->toContain('LateDelivery');
});

// ---------------------------------------------------------------- exceptions on open supplies

it('advises to expedite a supply that arrives after the shortage, defer one that arrives long before it and cancel one nobody needs', function (): void {
    $late = mrpRun([mrpItem('p')], [mrpDemand('p', '2027-03-20', '10')], [mrpSupply('p', '2027-03-29', '10', 'po-late')]);
    $early = mrpRun([mrpItem('p')], [mrpDemand('p', '2027-03-20', '10')], [mrpSupply('p', '2027-03-10', '10', 'po-early')]);
    $excess = mrpRun([mrpItem('p', ['on_hand' => '5'])], [], [mrpSupply('p', '2027-03-10', '10', 'po-excess')]);

    [$expedite] = mrpOf($late, 'p', 'RescheduleIn_Expedite');
    [$defer] = mrpOf($early, 'p', 'RescheduleOut_Defer');
    [$cancel] = mrpOf($excess, 'p', 'CancelExcessOrder');

    expect($expedite->supplyReference)->toBe('po-late')->and($expedite->plannedEnd->toDateString())->toBe('2027-03-20')->and($expedite->exceptions)->toBe(['Expedite'])
        ->and($defer->supplyReference)->toBe('po-early')->and($defer->plannedEnd->toDateString())->toBe('2027-03-20')->and($defer->exceptions)->toBe(['Defer'])
        ->and($cancel->supplyReference)->toBe('po-excess')->and($cancel->exceptions)->toBe(['ExcessSupply'])
        // the early supply covers the demand: no new order, and no advice for a supply that is needed on time
        ->and(mrpOf($early, 'p', 'PlannedPurchaseRequisition'))->toBe([]);
});

it('gives no advice for a supply that arrives exactly when needed or is in transit', function (): void {
    $onTime = mrpRun([mrpItem('p')], [mrpDemand('p', '2027-03-20', '10')], [mrpSupply('p', '2027-03-20', '10')]);
    $transit = mrpRun([mrpItem('p', ['on_hand' => '100'])], [], [mrpSupply('p', '2027-03-20', '10', 'tr-1', SupplyType::InTransit)]);

    expect($onTime->proposals)->toBe([])->and($transit->proposals)->toBe([]);
});

// ---------------------------------------------------------------- capacity

/**
 * Two make items with the same routing (60 min setup + 6 min per unit on wc1) due on Friday 2027-03-12, 50 units each:
 * 360 minutes per order on a work centre with 480 minutes per day.
 *
 * @return array{0: list<ItemPlanningData>, 1: list<\Modules\MrpPlanning\Domain\ValueObjects\DemandLine>}
 */
function mrpTwoOrdersOnOneWorkCenter(): array
{
    $routing = [new RoutingOperation(10, 'wc1', '60', '6')];

    return [
        [mrpItem('a', ['procurement' => 'make', 'lead' => 0, 'operations' => $routing]), mrpItem('b', ['procurement' => 'make', 'lead' => 0, 'operations' => $routing])],
        [mrpDemand('a', '2027-03-12', '50', 'so-a'), mrpDemand('b', '2027-03-12', '50', 'so-b')],
    ];
}

it('reports overloaded buckets when two orders stack on one work centre under infinite capacity', function (): void {
    [$items, $demands] = mrpTwoOrdersOnOneWorkCenter();
    $result = mrpRun($items, $demands, [], ['calendars' => ['wc1' => 480]]);

    $friday = array_values(array_filter($result->buckets, static fn (array $b): bool => $b['bucket_date'] === '2027-03-12'));

    expect($friday)->toHaveCount(1)
        ->and($friday[0]['loaded_minutes'])->toBe(720)->and($friday[0]['available_minutes'])->toBe(480)
        ->and(mrpOf($result, 'a')[0]->exceptions)->toContain('CapacityOverload')
        ->and(mrpOf($result, 'b')[0]->exceptions)->toContain('CapacityOverload');
});

it('levels the same orders against the finite capacity by starting the second one a day earlier', function (): void {
    [$items, $demands] = mrpTwoOrdersOnOneWorkCenter();
    $result = mrpRun($items, $demands, [], ['calendars' => ['wc1' => 480], 'capacity' => CapacitySchedulingMode::FiniteCapacityConstraint]);

    $loads = array_column($result->buckets, 'loaded_minutes', 'bucket_date');
    [$b] = mrpOf($result, 'b');

    expect($loads)->toBe(['2027-03-11' => 240, '2027-03-12' => 480])
        ->and(array_filter($result->buckets, static fn (array $bucket): bool => $bucket['loaded_minutes'] > $bucket['available_minutes']))->toBe([])
        ->and($b->plannedStart->toDateString())->toBe('2027-03-11')->and($b->plannedEnd->toDateString())->toBe('2027-03-12')
        ->and($b->exceptions)->not->toContain('CapacityOverload')->and($b->exceptions)->not->toContain('LateDelivery');
});

it('moves an operation to an alternative work centre when that finishes it later (backward) than the busy primary', function (): void {
    [$items, $demands] = mrpTwoOrdersOnOneWorkCenter();
    $result = mrpRun($items, $demands, [], [
        'calendars' => ['wc1' => 480, 'wc2' => 480],
        'alternatives' => ['wc1' => ['wc2']],
        'capacity' => CapacitySchedulingMode::FiniteCapacityConstraint,
    ]);

    $blocks = array_values(array_filter($result->blocks, static fn (ScheduleBlock $block): bool => $block->proposalId === mrpOf($result, 'b')[0]->id));

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]->workCenterId)->toBe('wc2')->and($blocks[0]->originalWorkCenterId)->toBe('wc1')
        ->and($blocks[0]->startDate->toDateString())->toBe('2027-03-12')
        ->and(mrpOf($result, 'b')[0]->workCenterId)->toBe('wc2');
});

it('falls back to a forward pass from today when a finite backward schedule would start in the past', function (): void {
    // 60 + 6 x 200 = 1,260 minutes due Tuesday 03-02: 480 on 03-02, 480 on 03-01, 300 would be on 02-26 -> forward from today.
    $items = [mrpItem('big', ['procurement' => 'make', 'lead' => 0, 'operations' => [new RoutingOperation(10, 'wc1', '60', '6')]])];
    $result = mrpRun($items, [mrpDemand('big', '2027-03-02', '200')], [], ['calendars' => ['wc1' => 480], 'capacity' => CapacitySchedulingMode::FiniteCapacityConstraint]);

    [$order] = mrpOf($result, 'big');

    expect($order->plannedStart->toDateString())->toBe('2027-03-01')->and($order->plannedEnd->toDateString())->toBe('2027-03-03')
        ->and($order->exceptions)->toContain('LateDelivery');
});

it('places the operations of a routing in sequence, each ending when the next one starts', function (): void {
    $routing = [new RoutingOperation(10, 'wc1', '0', '4'), new RoutingOperation(20, 'wc2', '0', '5')];
    $items = [mrpItem('p', ['procurement' => 'make', 'lead' => 0, 'operations' => $routing])];
    // 100 units: 400 min on wc1, 500 min on wc2 (480/day) -> wc2 takes 03-11 (20) and 03-12 (480), wc1 finishes on the 11th.
    $result = mrpRun($items, [mrpDemand('p', '2027-03-12', '100')], [], ['calendars' => ['wc1' => 480, 'wc2' => 480]]);

    $byOperation = [];
    foreach ($result->blocks as $block) {
        $byOperation[$block->sequence] = $block;
    }

    expect($byOperation[20]->startDate->toDateString())->toBe('2027-03-11')->and($byOperation[20]->endDate->toDateString())->toBe('2027-03-12')
        ->and($byOperation[10]->endDate->toDateString())->toBe('2027-03-11')
        ->and($byOperation[10]->minutes)->toBe(400)->and($byOperation[20]->minutes)->toBe(500);
});
