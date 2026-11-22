<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\MrpPlanning\Domain\Enums\CapacitySchedulingMode;
use Modules\MrpPlanning\Domain\Enums\DemandSourceType;
use Modules\MrpPlanning\Domain\Enums\LotSizingRule;
use Modules\MrpPlanning\Domain\Enums\SchedulingDirection;
use Modules\MrpPlanning\Domain\Enums\SupplyType;
use Modules\MrpPlanning\Domain\Services\CapacityScheduler;
use Modules\MrpPlanning\Domain\Services\LotSizingCalculator;
use Modules\MrpPlanning\Domain\Services\LowLevelCodeCalculator;
use Modules\MrpPlanning\Domain\Services\MrpCalculationService;
use Modules\MrpPlanning\Domain\ValueObjects\DemandLine;
use Modules\MrpPlanning\Domain\ValueObjects\ItemPlanningData;
use Modules\MrpPlanning\Domain\ValueObjects\MrpResult;
use Modules\MrpPlanning\Domain\ValueObjects\MrpSnapshot;
use Modules\MrpPlanning\Domain\ValueObjects\PlannedProposal;
use Modules\MrpPlanning\Domain\ValueObjects\RunParameters;
use Modules\MrpPlanning\Domain\ValueObjects\SupplyLine;
use Modules\MrpPlanning\Domain\ValueObjects\WorkCenterCalendar;

/*
| Builders of the pure MRP engine tests. "Today" is Monday 2027-03-01; all dates are written out so that the expectations
| can be checked against a calendar: 2027-03-19 is a Friday, 2027-03-14 a Sunday.
*/

/**
 * @param  array<string, mixed>  $o
 */
function mrpItem(string $id, array $o = []): ItemPlanningData
{
    return new ItemPlanningData(
        id: $id,
        code: strtoupper($id),
        name: ucfirst($id),
        procurement: $o['procurement'] ?? ItemPlanningData::Buy,
        leadTimeDays: $o['lead'] ?? 5,
        safetyStock: $o['safety'] ?? '0',
        onHand: $o['on_hand'] ?? '0',
        allocated: $o['allocated'] ?? '0',
        scrapPct: $o['scrap'] ?? '0',
        lotRule: $o['rule'] ?? LotSizingRule::LotForLot,
        moq: $o['moq'] ?? '0',
        multiple: $o['multiple'] ?? '0',
        eoq: $o['eoq'] ?? '0',
        fixedDays: $o['fixed_days'] ?? 0,
        supplierId: $o['supplier'] ?? null,
        supplierCountry: $o['country'] ?? null,
        customsDays: $o['customs'] ?? null,
        bomVersionId: $o['bom'] ?? null,
        components: $o['components'] ?? [],
        operations: $o['operations'] ?? [],
    );
}

function mrpDemand(string $itemId, string $date, string $quantity, string $id = 'so-1', DemandSourceType $type = DemandSourceType::SalesOrderLine): DemandLine
{
    return new DemandLine($type, $id, $itemId, CarbonImmutable::parse($date), $quantity);
}

function mrpSupply(string $itemId, string $date, string $quantity, string $reference = 'po-1', SupplyType $type = SupplyType::OpenPurchaseOrder): SupplyLine
{
    return new SupplyLine($type, $reference, $itemId, CarbonImmutable::parse($date), $quantity);
}

/**
 * @param  array<string, mixed>  $o
 */
function mrpParams(array $o = []): RunParameters
{
    return new RunParameters(
        today: CarbonImmutable::parse('2027-03-01'),
        planningHorizonDays: $o['horizon'] ?? 365,
        capacityMode: $o['capacity'] ?? CapacitySchedulingMode::InfiniteCapacity,
        direction: $o['direction'] ?? SchedulingDirection::BackwardFromDueDate,
        includeCrossBorderCustomsLeadTime: $o['customs'] ?? false,
        applySupplierShutdownCalendars: $o['shutdowns'] ?? false,
        tenantCountry: $o['country'] ?? 'CH',
    );
}

/**
 * @param  list<ItemPlanningData>  $items
 * @param  list<DemandLine>  $demands
 * @param  list<SupplyLine>  $supplies
 * @param  array<string, mixed>  $o
 */
function mrpRun(array $items, array $demands, array $supplies = [], array $o = []): MrpResult
{
    $byId = [];
    foreach ($items as $item) {
        $byId[$item->id] = $item;
    }

    $calendars = [];
    foreach ($o['calendars'] ?? [] as $id => $minutes) {
        $calendars[$id] = new WorkCenterCalendar($id, [1 => $minutes, 2 => $minutes, 3 => $minutes, 4 => $minutes, 5 => $minutes, 6 => 0, 7 => 0]);
    }

    $engine = new MrpCalculationService(new LowLevelCodeCalculator, new LotSizingCalculator, new CapacityScheduler);

    return $engine->calculate(new MrpSnapshot(mrpParams($o), $byId, $demands, $supplies, $o['shutdown_periods'] ?? [], $calendars, $o['alternatives'] ?? []));
}

/**
 * @return list<PlannedProposal>
 */
function mrpOf(MrpResult $result, string $itemId, ?string $type = null): array
{
    return array_values(array_filter($result->proposals, static fn (PlannedProposal $p): bool => $p->itemId === $itemId && ($type === null || $p->type->value === $type)));
}
