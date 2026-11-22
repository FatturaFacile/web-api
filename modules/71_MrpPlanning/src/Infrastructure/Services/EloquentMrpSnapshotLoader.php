<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Infrastructure\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\StockReservation;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Contracts\RoutingServiceInterface;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgBomComponent;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderLine;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Models\MfgWorkCenterCapacity;
use Modules\Manufacturing\Models\MfgWorkCenterShift;
use Modules\Manufacturing\Support\Money;
use Modules\MrpPlanning\Domain\Contracts\MasterScheduleGatewayInterface;
use Modules\MrpPlanning\Domain\Contracts\MrpSnapshotLoaderInterface;
use Modules\MrpPlanning\Domain\Enums\DemandSourceType;
use Modules\MrpPlanning\Domain\Enums\LotSizingRule;
use Modules\MrpPlanning\Domain\Enums\ProposalStatus;
use Modules\MrpPlanning\Domain\Enums\ProposalType;
use Modules\MrpPlanning\Domain\Enums\SupplyType;
use Modules\MrpPlanning\Domain\Services\LotSizingCalculator;
use Modules\MrpPlanning\Domain\ValueObjects\BomLine;
use Modules\MrpPlanning\Domain\ValueObjects\DemandLine;
use Modules\MrpPlanning\Domain\ValueObjects\ItemPlanningData;
use Modules\MrpPlanning\Domain\ValueObjects\MrpSnapshot;
use Modules\MrpPlanning\Domain\ValueObjects\RoutingOperation;
use Modules\MrpPlanning\Domain\ValueObjects\RunParameters;
use Modules\MrpPlanning\Domain\ValueObjects\ScheduleBlock;
use Modules\MrpPlanning\Domain\ValueObjects\ShutdownPeriod;
use Modules\MrpPlanning\Domain\ValueObjects\SupplyLine;
use Modules\MrpPlanning\Domain\ValueObjects\WorkCenterCalendar;
use Modules\MrpPlanning\Infrastructure\Models\MrpActionProposal;
use Modules\MrpPlanning\Infrastructure\Models\MrpItemParameter;
use Modules\MrpPlanning\Infrastructure\Models\MrpScheduleBlock;
use Modules\MrpPlanning\Infrastructure\Models\MrpShutdownPeriod;
use Modules\MrpPlanning\Infrastructure\Models\MrpWorkCenterAlternative;

/**
 * Builds the {@see MrpSnapshot} from the upstream modules:
 *
 * - items, effective BOM (Mod 060, phantoms collapsed, yield applied) and routing (Mod 061) of the manufacturing module;
 * - on-hand and reservations of the inventory (Mod 051), open sales order lines (Mod 037) and the MPS (Mod 062, through
 *   its gateway) as demand;
 * - open purchase orders (Mod 044/046), open production orders (Mod 064), stock in transit and the firm planned orders
 *   of earlier runs as scheduled receipts;
 * - work centre calendars (shifts and dated exceptions) and the alternative work centres of this module.
 */
final class EloquentMrpSnapshotLoader implements MrpSnapshotLoaderInterface
{
    /** Purchase order statuses whose open lines are expected receipts. */
    private const array OpenPurchaseStatuses = ['sent', 'partially_received'];

    private const array OpenSalesStatuses = ['confirmed', 'in_production'];

    public function __construct(
        private readonly BillOfMaterialsServiceInterface $boms,
        private readonly RoutingServiceInterface $routings,
        private readonly MasterScheduleGatewayInterface $masterSchedule,
    ) {}

    public function load(string $tenantId, RunParameters $parameters): MrpSnapshot
    {
        $today = $parameters->today->startOfDay();
        $end = $parameters->horizonEnd();
        $date = $today->toDateString();

        $all = MfgItem::query()->where('tenant_id', $tenantId)->where('is_active', true)->get()->keyBy(static fn (MfgItem $item): string => (string) $item->id);
        $planned = $all->reject(static fn (MfgItem $item): bool => $item->isPhantom());
        $params = MrpItemParameter::query()->where('tenant_id', $tenantId)->get()->keyBy('mfg_item_id');
        $stock = $this->stockPosition($tenantId, $planned);

        $items = [];
        $workCenterIds = [];
        foreach ($planned as $id => $item) {
            $bom = $this->boms->effectiveBomFor($item, $date);
            $routing = $this->routings->effectiveRoutingFor($item, $date);
            $operations = [];
            foreach ($routing === null ? [] : $routing->operations as $operation) {
                $operations[] = new RoutingOperation((int) $operation->sequence, (string) $operation->work_center_id, Money::of($operation->setup_time_minutes), Money::of($operation->run_time_minutes_per_unit), Money::of($operation->teardown_time_minutes));
                $workCenterIds[(string) $operation->work_center_id] = true;
            }
            $parameter = $params->get($id);
            $country = $parameter?->supplier_country !== null ? strtoupper((string) $parameter->supplier_country) : null;

            $items[$id] = new ItemPlanningData(
                id: $id,
                code: $item->item_code,
                name: $item->name,
                procurement: match ($item->procurementType()) {
                    'buy' => ItemPlanningData::Buy,
                    'subcontract' => ItemPlanningData::Subcontract,
                    default => ItemPlanningData::Make,
                },
                leadTimeDays: max(0, (int) $item->lead_time_days),
                safetyStock: Money::of($item->safety_stock),
                onHand: $stock[$id]['on_hand'] ?? '0',
                allocated: $stock[$id]['allocated'] ?? '0',
                scrapPct: Money::of($item->scrap_rate),
                lotRule: $parameter === null ? $this->defaultLotRule($item) : $parameter->lot_sizing_rule,
                moq: Money::of($item->min_lot_size),
                multiple: Money::of($item->lot_size_multiple),
                eoq: Money::of($parameter?->eoq_quantity),
                fixedDays: $parameter === null ? 0 : $parameter->fixed_days_of_supply,
                supplierId: $parameter !== null && $parameter->supplier_id !== null ? $parameter->supplier_id : ($country !== null ? 'country:'.$country : null),
                supplierCountry: $country,
                customsDays: $parameter?->customs_clearance_days,
                bomVersionId: $bom?->id,
                components: $bom instanceof MfgBom ? $this->collapse($bom, $all, $date) : [],
                operations: $operations,
                uom: $item->uom,
            );
        }

        $demands = [...$this->salesDemand($tenantId, $planned, $end), ...$this->masterSchedule->demandBetween($tenantId, $today, $end)];
        $supplies = [];
        $this->openProductionOrders($tenantId, $planned, $all, $date, $demands, $supplies);
        array_push($supplies, ...$this->openPurchases($tenantId, $planned), ...$this->inTransit($tenantId, $planned));
        $fixedBlocks = $this->firmProposals($tenantId, $items, $demands, $supplies);

        $alternatives = [];
        foreach (MrpWorkCenterAlternative::query()->where('tenant_id', $tenantId)->orderBy('priority')->get() as $alternative) {
            $alternatives[(string) $alternative->work_center_id][] = (string) $alternative->alternative_work_center_id;
            $workCenterIds[(string) $alternative->work_center_id] = true;
            $workCenterIds[(string) $alternative->alternative_work_center_id] = true;
        }

        return new MrpSnapshot(
            parameters: $parameters,
            items: $items,
            demands: $demands,
            supplies: $supplies,
            shutdowns: $this->shutdowns($tenantId, $items, $today, $end),
            calendars: $this->calendars(array_map('strval', array_keys($workCenterIds)), $today, $end->addDays(400)),
            alternatives: $alternatives,
            fixedBlocks: $fixedBlocks,
        );
    }

    /**
     * Daily minutes of the work centres: weekly shifts (or the daily hours on weekdays) and dated exceptions, times
     * efficiency and availability.
     *
     * @param  list<string>  $workCenterIds
     * @return array<string, WorkCenterCalendar>
     */
    public function calendars(array $workCenterIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $calendars = [];
        foreach (MfgWorkCenter::query()->whereIn('id', $workCenterIds)->get() as $workCenter) {
            $weekly = [];
            $shifts = MfgWorkCenterShift::query()->where('work_center_id', $workCenter->id)->get();
            for ($weekday = 1; $weekday <= 7; $weekday++) {
                $shift = $shifts->first(static fn (MfgWorkCenterShift $row): bool => $row->weekday === $weekday);
                $gross = $shifts->isNotEmpty() ? ($shift instanceof MfgWorkCenterShift ? Money::of($shift->available_hours) : '0') : ($weekday <= 5 ? Money::of($workCenter->capacity_hours_per_day) : '0');
                $weekly[$weekday] = $this->minutes($workCenter, $gross);
            }

            $exceptions = [];
            foreach (MfgWorkCenterCapacity::query()->where('work_center_id', $workCenter->id)->whereDate('calendar_date', '>=', $from->toDateString())->whereDate('calendar_date', '<=', $to->toDateString())->get() as $exception) {
                $exceptions[$exception->calendar_date->toDateString()] = $this->minutes($workCenter, Money::of($exception->available_hours));
            }

            $calendars[(string) $workCenter->id] = new WorkCenterCalendar((string) $workCenter->id, $weekly, $exceptions, $workCenter->name);
        }

        return $calendars;
    }

    private function minutes(MfgWorkCenter $workCenter, string $grossHours): int
    {
        return (int) Money::round(Money::mul($workCenter->effectiveHours($grossHours), '60'), 0);
    }

    /**
     * An item without explicit parameters keeps the lot rules of the manufacturing item: a multiple or a minimum lot.
     */
    private function defaultLotRule(MfgItem $item): LotSizingRule
    {
        return match (true) {
            Money::isPositive($item->lot_size_multiple) => LotSizingRule::OrderMultiple,
            Money::compare($item->min_lot_size, '1') > 0 => LotSizingRule::MinimumOrderQuantity,
            default => LotSizingRule::LotForLot,
        };
    }

    /**
     * Components of a BOM per parent unit with the phantom subassemblies exploded through: quantity divided by the yield,
     * scrap compounded along the phantom chain.
     *
     * @param  Collection<string, MfgItem>  $all
     * @param  array<string, true>  $path
     * @return list<BomLine>
     */
    private function collapse(MfgBom $bom, Collection $all, string $date, string $ratio = '1', string $scrapPct = '0', array $path = []): array
    {
        $lines = [];
        foreach (MfgBomComponent::query()->where('bom_id', $bom->id)->orderBy('sequence')->get() as $component) {
            $child = $all->get((string) $component->component_item_id);
            if (! $child instanceof MfgItem) {
                continue;
            }
            $yield = Money::isPositive($component->yield_percentage) ? Money::div($component->yield_percentage, '100') : '1';
            $quantity = Money::div(Money::mul($component->quantity_per, $ratio), $yield);
            $scrap = Money::mul(Money::sub(Money::mul(Money::add('1', Money::div($scrapPct, '100')), Money::add('1', Money::div($component->scrap_percentage, '100'))), '1'), '100');

            if ($component->is_phantom || $child->isPhantom()) {
                $sub = $this->boms->effectiveBomFor($child, $date);
                if ($sub instanceof MfgBom && ! isset($path[(string) $child->id])) {
                    array_push($lines, ...$this->collapse($sub, $all, $date, $quantity, $scrap, [...$path, (string) $child->id => true]));

                    continue;
                }
            }

            $lines[] = new BomLine((string) $child->id, Money::qty($quantity), Money::round($scrap, 4));
        }

        return $lines;
    }

    /**
     * On-hand stock and reservations made for other documents (component reservations of production orders are already
     * counted as demand) per manufacturing item.
     *
     * @param  Collection<string, MfgItem>  $items
     * @return array<string, array{on_hand: string, allocated: string}>
     */
    private function stockPosition(string $tenantId, Collection $items): array
    {
        $stockItems = $items->filter(static fn (MfgItem $item): bool => $item->stock_item_id !== null)->mapWithKeys(static fn (MfgItem $item): array => [(string) $item->stock_item_id => (string) $item->id]);
        if ($stockItems->isEmpty()) {
            return [];
        }

        $quants = StockQuant::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereIn('stock_item_id', $stockItems->keys()->all())
            ->groupBy('stock_item_id')->selectRaw('stock_item_id, SUM(quantity_on_hand) AS on_hand')->toBase()->get()->keyBy('stock_item_id');
        $reserved = StockReservation::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('status', 'active')
            ->where('reserved_for_type', '!=', 'mfg_production_order_line')->whereIn('stock_item_id', $stockItems->keys()->all())
            ->groupBy('stock_item_id')->selectRaw('stock_item_id, SUM(quantity_reserved) AS reserved')->toBase()->get()->keyBy('stock_item_id');

        $result = [];
        foreach ($stockItems as $stockItemId => $itemId) {
            $result[$itemId] = ['on_hand' => Money::of((string) ($quants[$stockItemId]->on_hand ?? '0')), 'allocated' => Money::of((string) ($reserved[$stockItemId]->reserved ?? '0'))];
        }

        return $result;
    }

    /**
     * Catalog product id => manufacturing item id.
     *
     * @param  Collection<string, MfgItem>  $items
     * @return array<string, string>
     */
    private function itemsByProduct(Collection $items): array
    {
        $stockProducts = StockItem::withoutGlobalScopes()->whereIn('id', $items->pluck('stock_item_id')->filter()->all())->pluck('product_id', 'id');
        $map = [];
        foreach ($items as $itemId => $item) {
            $productId = $item->product_id ?? ($item->stock_item_id !== null ? $stockProducts[$item->stock_item_id] ?? null : null);
            if ($productId !== null) {
                $map[(string) $productId] = (string) $itemId;
            }
        }

        return $map;
    }

    /**
     * Open lines of confirmed sales orders (Mod 037).
     *
     * @param  Collection<string, MfgItem>  $items
     * @return list<DemandLine>
     */
    private function salesDemand(string $tenantId, Collection $items, CarbonImmutable $end): array
    {
        $byProduct = $this->itemsByProduct($items);
        if ($byProduct === []) {
            return [];
        }

        $lines = DB::table('sales_order_items as l')
            ->join('sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->where('o.tenant_id', $tenantId)->whereNull('o.deleted_at')->whereNull('l.deleted_at')
            ->whereIn('o.status', self::OpenSalesStatuses)
            ->whereIn('l.product_id', array_keys($byProduct))
            ->get(['l.id', 'l.product_id', 'l.quantity', 'l.quantity_delivered', 'o.due_date', 'o.date']);

        $demand = [];
        foreach ($lines as $line) {
            $open = Money::sub((string) $line->quantity, (string) ($line->quantity_delivered ?? '0'));
            $date = CarbonImmutable::parse((string) ($line->due_date ?? $line->date));
            if (Money::isPositive($open) && $date->startOfDay() <= $end) {
                $demand[] = new DemandLine(DemandSourceType::SalesOrderLine, (string) $line->id, $byProduct[(string) $line->product_id], $date, Money::qty($open));
            }
        }

        return $demand;
    }

    /**
     * Open production orders (Mod 064): the remaining output is a scheduled receipt at the planned end, the open
     * components are demand at the planned start.
     *
     * @param  Collection<string, MfgItem>  $items
     * @param  Collection<string, MfgItem>  $all
     * @param  list<DemandLine>  $demands
     * @param  list<SupplyLine>  $supplies
     */
    private function openProductionOrders(string $tenantId, Collection $items, Collection $all, string $date, array &$demands, array &$supplies): void
    {
        $orders = MfgProductionOrder::query()->where('tenant_id', $tenantId)->whereIn('status', ['planned', 'released', 'in_progress'])->get();
        foreach ($orders as $order) {
            $remaining = Money::sub($order->target_quantity, $order->completed_quantity);
            if (! Money::isPositive($remaining)) {
                continue;
            }
            if ($items->has((string) $order->mfg_item_id)) {
                $supplies[] = new SupplyLine(SupplyType::OpenProductionOrder, (string) $order->id, (string) $order->mfg_item_id, CarbonImmutable::parse($order->planned_end_date), Money::qty($remaining));
            }

            $start = CarbonImmutable::parse($order->planned_start_date);
            if ($order->status === 'planned') {
                $bom = MfgBom::query()->find($order->bom_id);
                foreach ($bom instanceof MfgBom ? $this->collapse($bom, $all, $date) : [] as $line) {
                    if ($items->has($line->childItemId)) {
                        $demands[] = new DemandLine(DemandSourceType::ProductionOrder, (string) $order->id, $line->childItemId, $start, Money::qty(Money::mul($remaining, Money::mul($line->quantityPer, Money::add('1', Money::div($line->scrapPct, '100'))))));
                    }
                }

                continue;
            }

            foreach (MfgProductionOrderLine::query()->where('production_order_id', $order->id)->get() as $line) {
                $open = $line->openQuantity();
                if ($items->has((string) $line->mfg_item_id) && Money::isPositive($open)) {
                    $demands[] = new DemandLine(DemandSourceType::ProductionOrder, (string) $order->id, (string) $line->mfg_item_id, $start, Money::qty($open));
                }
            }
        }
    }

    /**
     * Open lines of sent purchase orders (Mod 044/046).
     *
     * @param  Collection<string, MfgItem>  $items
     * @return list<SupplyLine>
     */
    private function openPurchases(string $tenantId, Collection $items): array
    {
        $byProduct = $this->itemsByProduct($items);
        if ($byProduct === []) {
            return [];
        }

        $lines = DB::table('purchase_order_items as l')
            ->join('purchase_orders as o', 'o.id', '=', 'l.purchase_order_id')
            ->where('o.tenant_id', $tenantId)->whereNull('o.deleted_at')->whereNull('l.deleted_at')
            ->whereIn('o.status', self::OpenPurchaseStatuses)
            ->whereIn('l.product_id', array_keys($byProduct))
            ->get(['l.id', 'l.product_id', 'l.quantity', 'l.quantity_received', 'o.delivery_due_date', 'o.date']);

        $supplies = [];
        foreach ($lines as $line) {
            $open = Money::sub((string) $line->quantity, (string) ($line->quantity_received ?? '0'));
            if (Money::isPositive($open)) {
                $supplies[] = new SupplyLine(SupplyType::OpenPurchaseOrder, (string) $line->id, $byProduct[(string) $line->product_id], CarbonImmutable::parse((string) ($line->delivery_due_date ?? $line->date)), Money::qty($open));
            }
        }

        return $supplies;
    }

    /**
     * Transfer order lines shipped and not yet received.
     *
     * @param  Collection<string, MfgItem>  $items
     * @return list<SupplyLine>
     */
    private function inTransit(string $tenantId, Collection $items): array
    {
        $byStockItem = $items->filter(static fn (MfgItem $item): bool => $item->stock_item_id !== null)->mapWithKeys(static fn (MfgItem $item): array => [(string) $item->stock_item_id => (string) $item->id]);
        if ($byStockItem->isEmpty()) {
            return [];
        }

        $lines = DB::table('transfer_order_lines as l')
            ->join('transfer_orders as o', 'o.id', '=', 'l.transfer_order_id')
            ->where('o.tenant_id', $tenantId)->whereNull('o.deleted_at')->whereNull('l.deleted_at')
            ->whereIn('o.status', ['in_transit', 'partially_received'])
            ->whereIn('l.stock_item_id', $byStockItem->keys()->all())
            ->get(['l.id', 'l.stock_item_id', 'l.quantity_shipped', 'l.quantity_received', 'l.quantity_damaged', 'o.requested_date', 'o.shipped_at']);

        $supplies = [];
        foreach ($lines as $line) {
            $open = Money::sub(Money::sub((string) $line->quantity_shipped, (string) $line->quantity_received), (string) ($line->quantity_damaged ?? '0'));
            if (Money::isPositive($open)) {
                $supplies[] = new SupplyLine(SupplyType::InTransit, (string) $line->id, $byStockItem[(string) $line->stock_item_id], CarbonImmutable::parse((string) ($line->requested_date ?? $line->shipped_at ?? now())), Money::qty($open));
            }
        }

        return $supplies;
    }

    /**
     * Firm planned orders of earlier runs are commitments: their output is a scheduled receipt, their components stay
     * demand, and their schedule blocks stay where they are and keep loading the work centres.
     *
     * @param  array<string, ItemPlanningData>  $items
     * @param  list<DemandLine>  $demands
     * @param  list<SupplyLine>  $supplies
     * @return list<ScheduleBlock>
     */
    private function firmProposals(string $tenantId, array $items, array &$demands, array &$supplies): array
    {
        $lots = new LotSizingCalculator;
        $blocks = [];
        $firm = MrpActionProposal::query()->where('tenant_id', $tenantId)->where('status', ProposalStatus::Firm->value)->get()
            ->filter(static fn (MrpActionProposal $proposal): bool => $proposal->proposal_type->isNewOrder() && isset($items[$proposal->mfg_item_id]));

        foreach ($firm as $proposal) {
            $item = $items[(string) $proposal->mfg_item_id];
            $supplies[] = new SupplyLine(
                $proposal->proposal_type === ProposalType::PlannedPurchaseRequisition ? SupplyType::OpenPurchaseOrder : SupplyType::OpenProductionOrder,
                (string) $proposal->id, $item->id, CarbonImmutable::parse($proposal->need_by_date), $lots->goodOutput((string) $proposal->suggested_order_qty, $item->scrapPct),
            );
            if ($item->procurement !== ItemPlanningData::Buy) {
                foreach ($item->components as $line) {
                    $demands[] = new DemandLine(DemandSourceType::MrpProposal, (string) $proposal->id, $line->childItemId, CarbonImmutable::parse($proposal->release_order_by_date), $lots->withScrap(Money::mul((string) $proposal->suggested_order_qty, $line->quantityPer), $line->scrapPct));
                }
            }
            foreach (MrpScheduleBlock::query()->where('proposal_id', $proposal->id)->get() as $block) {
                $blocks[] = new ScheduleBlock((string) $block->id, (string) $proposal->id, (string) $block->work_center_id, (int) $block->operation_sequence, CarbonImmutable::parse($block->start_date), CarbonImmutable::parse($block->end_date), (int) $block->minutes, $block->segments ?? [], true, $block->original_work_center_id);
            }
        }

        return $blocks;
    }

    /**
     * Closures per supplier calendar key: the tenant's periods and the yearly defaults of the supplier country.
     *
     * @param  array<string, ItemPlanningData>  $items
     * @return list<ShutdownPeriod>
     */
    private function shutdowns(string $tenantId, array $items, CarbonImmutable $today, CarbonImmutable $end): array
    {
        $periods = [];
        foreach (MrpShutdownPeriod::query()->where('tenant_id', $tenantId)->whereDate('ends_on', '>=', $today->toDateString())->whereDate('starts_on', '<=', $end->toDateString())->get() as $period) {
            $keys = $period->supplier_id !== null ? [(string) $period->supplier_id] : ($period->country !== null ? $this->keysOfCountry($items, strtoupper($period->country)) : [null]);
            foreach ($keys as $key) {
                $periods[] = new ShutdownPeriod($key, CarbonImmutable::parse($period->starts_on), CarbonImmutable::parse($period->ends_on), $period->label);
            }
        }

        /** @var array<string, list<array{label: string, from: string, to: string}>> $defaults */
        $defaults = (array) config('mrp_planning.shutdown_defaults', []);
        foreach ($defaults as $country => $ranges) {
            foreach ($this->keysOfCountry($items, strtoupper((string) $country)) as $key) {
                for ($year = $today->year - 1; $year <= $end->year; $year++) {
                    foreach ($ranges as $range) {
                        $from = CarbonImmutable::parse($year.'-'.$range['from']);
                        $to = CarbonImmutable::parse($year.'-'.$range['to']);
                        $periods[] = new ShutdownPeriod($key, $from, $to->lt($from) ? $to->addYear() : $to, $range['label']);
                    }
                }
            }
        }

        return $periods;
    }

    /**
     * @param  array<string, ItemPlanningData>  $items
     * @return list<string>
     */
    private function keysOfCountry(array $items, string $country): array
    {
        $keys = [];
        foreach ($items as $item) {
            if (strtoupper((string) $item->supplierCountry) === $country) {
                $keys[$item->supplierId] = $item->supplierId;
            }
        }

        return array_values($keys);
    }
}
