<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Manufacturing\Support\Money;
use Modules\MrpPlanning\Domain\Enums\DemandSourceType;
use Modules\MrpPlanning\Domain\Enums\ExceptionCode;
use Modules\MrpPlanning\Domain\Enums\LotSizingRule;
use Modules\MrpPlanning\Domain\Enums\ProposalType;
use Modules\MrpPlanning\Domain\Enums\SupplyType;
use Modules\MrpPlanning\Domain\ValueObjects\ItemPlanningData;
use Modules\MrpPlanning\Domain\ValueObjects\MrpResult;
use Modules\MrpPlanning\Domain\ValueObjects\MrpSnapshot;
use Modules\MrpPlanning\Domain\ValueObjects\PlannedProposal;
use Modules\MrpPlanning\Domain\ValueObjects\RunParameters;
use Modules\MrpPlanning\Domain\ValueObjects\SchedulableOrder;
use Modules\MrpPlanning\Domain\ValueObjects\ScheduleBlock;
use Modules\MrpPlanning\Domain\ValueObjects\SupplyLine;

/**
 * Time-phased MRP: items are netted in low-level-code order on daily buckets.
 *
 *   projected(t) = projected(t-1) + scheduled receipts(t) - gross requirements(t)       starting at on-hand - allocated
 *   net(t)       = max(0, safety stock - projected(t))                                  the shortfall against the floor
 *
 * The safety stock is a floor of the projection, not an extra requirement added on top of it, so it is never counted
 * twice. A shortfall creates a planned order sized by the lot rule (scrap added first), dated by the lead time, the
 * supplier shutdown calendars and the customs clearance days; a make order explodes its start quantity through the BOM
 * into dependent demand of the next levels at its release date. Open supplies that arrive too late, too early or not at
 * all produce expedite, defer and cancel advice. Capacity is planned afterwards by the {@see CapacityScheduler}.
 *
 * The calculation is pure: it reads a {@see MrpSnapshot} and returns a {@see MrpResult}.
 */
final class MrpCalculationService
{
    public function __construct(
        private readonly LowLevelCodeCalculator $levels,
        private readonly LotSizingCalculator $lots,
        private readonly CapacityScheduler $scheduler,
    ) {}

    public function calculate(MrpSnapshot $snapshot): MrpResult
    {
        $params = $snapshot->parameters;
        $today = $params->today->startOfDay();
        $calendar = new PlanningCalendar($snapshot->shutdowns, $params->applySupplierShutdownCalendars);
        $levels = $this->levels->calculate($snapshot->items);

        /** @var array<string, list<array{date: CarbonImmutable, quantity: string, type: string, id: string}>> $lines */
        $lines = [];
        foreach ($snapshot->demands as $demand) {
            if (! isset($snapshot->items[$demand->itemId]) || $demand->date->startOfDay() > $params->horizonEnd() || ! Money::isPositive($demand->quantity)) {
                continue;
            }
            $lines[$demand->itemId][] = ['date' => max($today, $demand->date->startOfDay()), 'quantity' => $demand->quantity, 'type' => $demand->sourceType->value, 'id' => $demand->sourceId];
        }

        $supplies = [];
        foreach ($snapshot->supplies as $supply) {
            if (isset($snapshot->items[$supply->itemId]) && Money::isPositive($supply->quantity)) {
                $supplies[$supply->itemId][] = $supply;
            }
        }

        $ordered = array_values($snapshot->items);
        usort($ordered, static fn (ItemPlanningData $a, ItemPlanningData $b): int => [$levels[$a->id], $a->code] <=> [$levels[$b->id], $b->code]);

        $proposals = [];
        foreach ($ordered as $item) {
            $itemLines = $lines[$item->id] ?? [];
            usort($itemLines, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
            $itemSupplies = $supplies[$item->id] ?? [];

            foreach ($this->planItem($item, $levels[$item->id], $itemLines, $itemSupplies, $params, $calendar, $lines) as $proposal) {
                $proposals[] = $proposal;
            }
            foreach ($this->rescheduleAdvice($item, $levels[$item->id], $itemLines, $itemSupplies, $today) as $advice) {
                $proposals[] = $advice;
            }
        }

        [$blocks, $buckets] = $this->planCapacity($proposals, $snapshot);

        return new MrpResult($proposals, $blocks, $buckets, $levels);
    }

    /**
     * Gross-to-net netting of one item over the horizon.
     *
     * @param  list<array{date: CarbonImmutable, quantity: string, type: string, id: string}>  $itemLines
     * @param  list<SupplyLine>  $itemSupplies
     * @param  array<string, list<array{date: CarbonImmutable, quantity: string, type: string, id: string}>>  $lines  demand of all items, extended here with the dependent demand of the components
     * @return list<PlannedProposal>
     */
    private function planItem(ItemPlanningData $item, int $level, array $itemLines, array $itemSupplies, RunParameters $params, PlanningCalendar $calendar, array &$lines): array
    {
        $today = $params->today->startOfDay();
        $safety = Money::of($item->safetyStock);
        $projected = Money::sub($item->onHand, $item->allocated);

        $grossByDate = [];
        foreach ($itemLines as $line) {
            $grossByDate[$line['date']->toDateString()][] = $line;
        }
        $receiptsByDate = [];
        foreach ($itemSupplies as $supply) {
            $key = max($today, $supply->date->startOfDay())->toDateString();
            $receiptsByDate[$key] = Money::add($receiptsByDate[$key] ?? '0', $supply->quantity);
        }

        $dates = array_unique([$today->toDateString(), ...array_keys($grossByDate), ...array_keys($receiptsByDate)]);
        sort($dates);

        $proposals = [];
        foreach ($dates as $dateKey) {
            $date = CarbonImmutable::parse($dateKey);
            $dayLines = $grossByDate[$dateKey] ?? [];
            $dayGross = array_reduce($dayLines, static fn (string $sum, array $line): string => Money::add($sum, $line['quantity']), '0');

            $projected = Money::sub(Money::add($projected, $receiptsByDate[$dateKey] ?? '0'), $dayGross);
            if (Money::compare($projected, $safety) >= 0) {
                continue;
            }

            $shortfall = Money::sub($safety, $projected);
            $window = $item->lotRule === LotSizingRule::FixedDaysOfSupply ? $this->windowShortfall($item, $date, $projected, $grossByDate, $receiptsByDate, $safety) : '0';
            $orderQty = $this->lots->orderQuantity($item, $shortfall, $window);
            $before = $projected;
            $projected = Money::add($projected, $this->lots->goodOutput($orderQty, $item->scrapPct));
            if (Money::compare($projected, $safety) < 0) {
                $projected = $safety; // rounding of the good output must not open a phantom shortfall
            }

            $proposal = $this->buildProposal($item, $level, $date, $dayGross, $before, $shortfall, $orderQty, $dayLines, $safety, $params, $calendar);
            $proposals[] = $proposal;

            if ($item->procurement !== ItemPlanningData::Buy) {
                foreach ($item->components as $component) {
                    $qty = $this->lots->withScrap(Money::mul($orderQty, $component->quantityPer), $component->scrapPct);
                    if (Money::isPositive($qty)) {
                        $lines[$component->childItemId][] = ['date' => max($today, $proposal->releaseBy->startOfDay()), 'quantity' => $qty, 'type' => DemandSourceType::MrpProposal->value, 'id' => $proposal->id];
                    }
                }
            }
        }

        return $proposals;
    }

    /**
     * @param  list<array{date: CarbonImmutable, quantity: string, type: string, id: string}>  $dayLines
     */
    private function buildProposal(ItemPlanningData $item, int $level, CarbonImmutable $needBy, string $dayGross, string $projectedBefore, string $shortfall, string $orderQty, array $dayLines, string $safety, RunParameters $params, PlanningCalendar $calendar): PlannedProposal
    {
        $today = $params->today->startOfDay();
        $customs = $this->customsDays($item, $params);
        $exceptions = [];

        if (! $params->isBackward()) {
            $release = $calendar->nextWorkingDay($today, $item->supplierId);
            $lead = $calendar->addLeadTime($release, $item->leadTimeDays, $item->supplierId);
            $shifted = $lead['shifted'] || $release->ne($today);
            $start = $release;
            $end = $lead['date']->addDays($customs);
            if ($end > $needBy) {
                $exceptions[] = ExceptionCode::LateDelivery->value;
            }
        } else {
            // Customs clearance sits between the supplier's shipment and our receipt: it is taken off the need date first,
            // the supplier's lead time (which skips the supplier's shutdowns) is counted back from the shipment date.
            $base = $calendar->subtractLeadTime($needBy->subDays($customs), $item->leadTimeDays, $item->supplierId);
            $release = $base['date'];
            $shifted = $base['shifted'];
            $start = $release;
            $end = $needBy;
            if ($release < $today) {
                $exceptions[] = ExceptionCode::PastDueRelease->value;
            }
        }

        if ($shifted) {
            $exceptions[] = ExceptionCode::HolidayShiftApplied->value;
        }
        if ($customs > 0) {
            $exceptions[] = ExceptionCode::CustomsLeadTimeApplied->value;
        }
        if (Money::isPositive($safety) && ! Money::isNegative($projectedBefore)) {
            $exceptions[] = ExceptionCode::BelowSafetyStock->value;
        }

        $type = match ($item->procurement) {
            ItemPlanningData::Buy => ProposalType::PlannedPurchaseRequisition,
            ItemPlanningData::Subcontract => ProposalType::PlannedSubcontractOrder,
            default => ProposalType::PlannedProductionOrder,
        };

        return new PlannedProposal(
            id: (string) Str::uuid(),
            type: $type,
            itemId: $item->id,
            lowLevelCode: $level,
            grossQty: Money::qty($dayGross),
            projectedOnHandQty: Money::qty($projectedBefore),
            netQty: Money::qty($shortfall),
            requiredQty: Money::qty($shortfall),
            suggestedQty: $orderQty,
            needBy: $needBy,
            releaseBy: $release,
            plannedStart: $start,
            plannedEnd: $end,
            pegging: $this->peg($dayLines, $shortfall, $safety, $item->id),
            exceptions: $exceptions,
            bomVersionId: $item->bomVersionId,
        );
    }

    /**
     * Demand covered by a proposal: the day's requirements first-come first-served, the rest is safety stock and, when
     * the stock was already over-allocated, allocated stock.
     *
     * @param  list<array{date: CarbonImmutable, quantity: string, type: string, id: string}>  $dayLines
     * @return list<array{type: string, id: string, quantity: string}>
     */
    private function peg(array $dayLines, string $shortfall, string $safety, string $itemId): array
    {
        $remaining = $shortfall;
        $pegs = [];
        foreach ($dayLines as $line) {
            if (! Money::isPositive($remaining)) {
                break;
            }
            $take = Money::min($remaining, $line['quantity']);
            $pegs[] = ['type' => $line['type'], 'id' => $line['id'], 'quantity' => Money::qty($take)];
            $remaining = Money::sub($remaining, $take);
        }
        if (Money::isPositive($remaining) && Money::isPositive($safety)) {
            $take = Money::min($remaining, $safety);
            $pegs[] = ['type' => DemandSourceType::SafetyStock->value, 'id' => $itemId, 'quantity' => Money::qty($take)];
            $remaining = Money::sub($remaining, $take);
        }
        if (Money::isPositive($remaining)) {
            $pegs[] = ['type' => DemandSourceType::Allocation->value, 'id' => $itemId, 'quantity' => Money::qty($remaining)];
        }

        return $pegs;
    }

    /**
     * Customs clearance days: the Swiss border is the customs border towards Italy and the EU, in both directions.
     */
    private function customsDays(ItemPlanningData $item, RunParameters $params): int
    {
        if (! $params->includeCrossBorderCustomsLeadTime || $item->procurement === ItemPlanningData::Make || $item->supplierCountry === null) {
            return 0;
        }
        $supplier = strtoupper($item->supplierCountry);
        $home = strtoupper($params->tenantCountry);
        if ($supplier === $home || ($supplier !== 'CH' && $home !== 'CH')) {
            return 0;
        }

        return max(0, $item->customsDays ?? $params->defaultCustomsDays);
    }

    /**
     * FixedDaysOfSupply: the shortfall at the end of the window of days that starts at the shortage date.
     *
     * @param  array<string, list<array{date: CarbonImmutable, quantity: string, type: string, id: string}>>  $grossByDate
     * @param  array<string, string>  $receiptsByDate
     */
    private function windowShortfall(ItemPlanningData $item, CarbonImmutable $date, string $projected, array $grossByDate, array $receiptsByDate, string $safety): string
    {
        if ($item->fixedDays <= 1) {
            return '0';
        }
        $from = $date->toDateString();
        $end = $date->addDays($item->fixedDays - 1)->toDateString();
        $running = $projected;
        foreach ($grossByDate as $key => $dayLines) {
            if ($key > $from && $key <= $end) {
                $running = Money::sub($running, array_reduce($dayLines, static fn (string $sum, array $line): string => Money::add($sum, $line['quantity']), '0'));
            }
        }
        foreach ($receiptsByDate as $key => $quantity) {
            if ($key > $from && $key <= $end) {
                $running = Money::add($running, $quantity);
            }
        }

        return Money::compare($running, $safety) < 0 ? Money::sub($safety, $running) : '0';
    }

    /**
     * Expedite, defer and cancel advice for the open supplies of an item. Each supply is taken out of the projection in
     * turn: the first shortage without it is the date it is really needed.
     *
     * @param  list<array{date: CarbonImmutable, quantity: string, type: string, id: string}>  $itemLines
     * @param  list<SupplyLine>  $itemSupplies
     * @return list<PlannedProposal>
     */
    private function rescheduleAdvice(ItemPlanningData $item, int $level, array $itemLines, array $itemSupplies, CarbonImmutable $today): array
    {
        $advice = [];
        foreach ($itemSupplies as $index => $supply) {
            if ($supply->type === SupplyType::InTransit) {
                continue;
            }
            $others = $itemSupplies;
            unset($others[$index]);
            $needed = $this->firstShortage($item, $itemLines, $others, $today);
            $current = max($today, $supply->date->startOfDay());

            if ($needed === null) {
                $type = ProposalType::CancelExcessOrder;
                $code = ExceptionCode::ExcessSupply;
                $target = $current;
            } elseif ($needed < $current) {
                $type = ProposalType::RescheduleInExpedite;
                $code = ExceptionCode::Expedite;
                $target = $needed;
            } elseif ($needed > $current) {
                $type = ProposalType::RescheduleOutDefer;
                $code = ExceptionCode::Defer;
                $target = $needed;
            } else {
                continue;
            }

            $dayLines = array_values(array_filter($itemLines, static fn (array $line): bool => $line['date']->equalTo($target)));
            $advice[] = new PlannedProposal(
                id: (string) Str::uuid(),
                type: $type,
                itemId: $item->id,
                lowLevelCode: $level,
                grossQty: '0',
                projectedOnHandQty: '0',
                netQty: '0',
                requiredQty: Money::qty($supply->quantity),
                suggestedQty: Money::qty($supply->quantity),
                needBy: $target,
                releaseBy: $current,
                plannedStart: $current,
                plannedEnd: $target,
                pegging: array_map(static fn (array $line): array => ['type' => $line['type'], 'id' => $line['id'], 'quantity' => Money::qty($line['quantity'])], $dayLines),
                exceptions: [$code->value],
                supplyReference: $supply->reference,
            );
        }

        return $advice;
    }

    /**
     * The first day on which the projection of the item falls below its safety stock.
     *
     * @param  list<array{date: CarbonImmutable, quantity: string, type: string, id: string}>  $itemLines
     * @param  array<int, SupplyLine>  $supplies
     */
    private function firstShortage(ItemPlanningData $item, array $itemLines, array $supplies, CarbonImmutable $today): ?CarbonImmutable
    {
        $events = [];
        foreach ($itemLines as $line) {
            $key = $line['date']->toDateString();
            $events[$key]['gross'] = Money::add($events[$key]['gross'] ?? '0', $line['quantity']);
        }
        foreach ($supplies as $supply) {
            $key = max($today, $supply->date->startOfDay())->toDateString();
            $events[$key]['receipt'] = Money::add($events[$key]['receipt'] ?? '0', $supply->quantity);
        }
        $events[$today->toDateString()] ??= [];
        ksort($events);

        $projected = Money::sub($item->onHand, $item->allocated);
        foreach ($events as $date => $event) {
            $projected = Money::sub(Money::add($projected, $event['receipt'] ?? '0'), $event['gross'] ?? '0');
            if (Money::compare($projected, $item->safetyStock) < 0) {
                return CarbonImmutable::parse((string) $date);
            }
        }

        return null;
    }

    /**
     * Capacity requirements planning of the planned production orders that have a routing.
     *
     * @param  list<PlannedProposal>  $proposals
     * @return array{0: list<ScheduleBlock>, 1: list<array{work_center_id: string, bucket_date: string, available_minutes: int, loaded_minutes: int}>}
     */
    private function planCapacity(array $proposals, MrpSnapshot $snapshot): array
    {
        $params = $snapshot->parameters;
        $orders = [];
        $byId = [];
        foreach ($proposals as $proposal) {
            $operations = $snapshot->items[$proposal->itemId]->operations;
            if ($proposal->type === ProposalType::PlannedProductionOrder && $operations !== []) {
                $orders[] = new SchedulableOrder($proposal->id, $proposal->suggestedQty, $proposal->needBy, $proposal->plannedStart, $operations);
                $byId[$proposal->id] = $proposal;
            }
        }
        if ($orders === []) {
            return [[], $this->scheduler->buckets($snapshot->fixedBlocks, $snapshot->calendars)];
        }

        $all = $this->scheduler->schedule($orders, $snapshot->calendars, $snapshot->alternatives, $params->today, $params->isFinite(), $params->isBackward(), $snapshot->fixedBlocks);
        $buckets = $this->scheduler->buckets($all, $snapshot->calendars);
        $blocks = array_values(array_filter($all, static fn (ScheduleBlock $block): bool => isset($byId[$block->proposalId])));
        $this->applyScheduleToProposals($byId, $blocks, $buckets, $params);

        return [$blocks, $buckets];
    }

    /**
     * Copies dates and work centre of the schedule to the proposals and flags overloads and late finishes.
     *
     * @param  array<string, PlannedProposal>  $byId
     * @param  list<ScheduleBlock>  $blocks
     * @param  list<array{work_center_id: string, bucket_date: string, available_minutes: int, loaded_minutes: int}>  $buckets
     */
    private function applyScheduleToProposals(array $byId, array $blocks, array $buckets, RunParameters $params): void
    {
        $overloaded = [];
        foreach ($buckets as $bucket) {
            if ($bucket['loaded_minutes'] > $bucket['available_minutes']) {
                $overloaded[$bucket['work_center_id'].'|'.$bucket['bucket_date']] = true;
            }
        }

        $grouped = [];
        foreach ($blocks as $block) {
            $grouped[$block->proposalId][] = $block;
        }

        foreach ($grouped as $proposalId => $proposalBlocks) {
            $proposal = $byId[$proposalId] ?? null;
            if ($proposal === null) {
                continue;
            }
            usort($proposalBlocks, static fn (ScheduleBlock $a, ScheduleBlock $b): int => $a->sequence <=> $b->sequence);
            $proposal->plannedStart = min(array_map(static fn (ScheduleBlock $b): CarbonImmutable => $b->startDate, $proposalBlocks));
            $proposal->plannedEnd = max(array_map(static fn (ScheduleBlock $b): CarbonImmutable => $b->endDate, $proposalBlocks));
            $proposal->workCenterId = $proposalBlocks[0]->workCenterId;

            foreach ($proposalBlocks as $block) {
                foreach ($block->segments as $segment) {
                    if (isset($overloaded[$block->workCenterId.'|'.$segment['date']])) {
                        $proposal->addException(ExceptionCode::CapacityOverload->value);
                    }
                }
            }
            if ($proposal->plannedEnd > $proposal->needBy) {
                $proposal->addException(ExceptionCode::LateDelivery->value);
            }
            if ($proposal->plannedStart < $params->today->startOfDay()) {
                $proposal->addException(ExceptionCode::PastDueRelease->value);
            }
        }
    }
}
