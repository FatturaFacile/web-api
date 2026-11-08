<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\StockReservation;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Contracts\MrpServiceInterface;
use Modules\Manufacturing\Contracts\ProductionPlanningServiceInterface;
use Modules\Manufacturing\Contracts\RoutingServiceInterface;
use Modules\Manufacturing\DTOs\CreateProductionOrderDTO;
use Modules\Manufacturing\DTOs\RunMrpDTO;
use Modules\Manufacturing\Events\MRPRunCompletedEvent;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgDemandForecast;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgMrpPegging;
use Modules\Manufacturing\Models\MfgMrpPlannedOrder;
use Modules\Manufacturing\Models\MfgMrpRun;
use Modules\Manufacturing\Models\MfgMrpShortage;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderLine;
use Modules\Manufacturing\Support\Money;

/**
 * Regenerative MRP. Planned orders of earlier runs that were not converted are superseded. Items are netted level by
 * level (low-level code), so the dependent demand of a parent's planned orders reaches its components:
 *
 * - gross requirements: open lines of confirmed or in-production sales orders, forecasts less the sales demand of the
 *   same month (forecast consumption), open component quantities of released orders, components of planned orders;
 * - stock: on hand less reservations made for other documents (component reservations are already counted as
 *   demand);
 * - scheduled receipts: open production orders at their planned end, open lines of sent purchase orders at their due
 *   date;
 * - when the projected stock of a day falls below the safety stock, a planned order covers the gap with the item's lot
 *   size rules (minimum, multiple, maximum), due that day and starting its lead time earlier.
 *
 * Purchase suggestions are data only: the Procurement module converts them.
 */
final readonly class MrpService implements MrpServiceInterface
{
    /** Sales order statuses that still carry demand. */
    private const array OpenSalesStatuses = ['confirmed', 'in_production'];

    /** Purchase order statuses whose open lines are expected receipts. */
    private const array OpenPurchaseStatuses = ['sent'];

    public function __construct(
        private BillOfMaterialsServiceInterface $boms,
        private RoutingServiceInterface $routings,
        private ProductionPlanningServiceInterface $planning,
        private EventDispatcher $dispatcher,
    ) {}

    public function executeMrpRun(RunMrpDTO $dto): MfgMrpRun
    {
        return DB::transaction(function () use ($dto): MfgMrpRun {
            Tenant::query()->whereKey($dto->tenantId)->lockForUpdate()->first();

            $start = CarbonImmutable::parse($dto->horizonStartDate)->startOfDay();
            $end = CarbonImmutable::parse($dto->horizonEndDate)->startOfDay();
            if ($end->lt($start)) {
                throw new ManufacturingOperationException('The horizon must end after it starts.', 'horizon_end_date');
            }

            $run = MfgMrpRun::create([
                'tenant_id' => $dto->tenantId,
                'run_number' => 'MRP-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4)),
                'run_type' => $dto->runType,
                'status' => 'running',
                'horizon_start_date' => $start->toDateString(),
                'horizon_end_date' => $end->toDateString(),
                'executed_by' => $dto->executedBy !== null && Str::isUuid($dto->executedBy) ? $dto->executedBy : null,
                'executed_at' => now(),
                'parameters' => $dto->parameters,
            ]);

            MfgMrpPlannedOrder::query()->where('tenant_id', $dto->tenantId)->where('status', 'planned')->update(['status' => 'superseded']);

            $items = MfgItem::query()->where('tenant_id', $dto->tenantId)->where('is_active', true)
                ->when($dto->itemIds !== null && $dto->itemIds !== [], static fn ($query) => $query->whereIn('id', $dto->itemIds))
                ->get()->keyBy(static fn (MfgItem $item): string => (string) $item->id);

            $levels = $this->lowLevelCodes($items, $start->toDateString());
            $demand = $this->independentDemand($dto->tenantId, $items, $start, $end);
            $receipts = [];
            $this->openOrders($dto->tenantId, $items, $start, $end, $demand, $receipts);
            $this->openPurchases($dto->tenantId, $items, $start, $end, $receipts);
            $onHand = $this->onHand($dto->tenantId, $items);

            $plannedCount = 0;
            $shortageCount = 0;
            $sorted = $items->sortBy(static fn (MfgItem $item): int => $levels[(string) $item->id] ?? 0);

            foreach ($sorted as $itemId => $item) {
                $result = $this->netItem($run, $item, $levels[$itemId] ?? 0, $onHand[$itemId] ?? '0', $demand[$itemId] ?? [], $receipts[$itemId] ?? [], $start, $end, $items, $demand);
                $plannedCount += $result['planned'];
                $shortageCount += $result['shortage'] ? 1 : 0;
            }

            $run->update([
                'status' => 'completed',
                'total_items_processed' => $items->count(),
                'total_planned_orders' => $plannedCount,
                'total_shortages' => $shortageCount,
            ]);

            $this->dispatcher->publishAndDispatch(new MRPRunCompletedEvent(
                mrpRunId: (string) $run->id,
                runNumber: $run->run_number,
                runType: $run->run_type,
                totalPlannedOrders: $plannedCount,
                totalShortages: $shortageCount,
                tenantId: $dto->tenantId
            ));

            return $run->load(['plannedOrders', 'shortages']);
        });
    }

    public function convertPlannedOrder(MfgMrpPlannedOrder $plannedOrder, ?string $actorUserId = null): MfgProductionOrder
    {
        return DB::transaction(function () use ($plannedOrder, $actorUserId): MfgProductionOrder {
            $locked = MfgMrpPlannedOrder::query()->whereKey($plannedOrder->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isMake()) {
                throw new ManufacturingOperationException('Purchase suggestions are converted by the Procurement module.', 'order_type');
            }
            if ($locked->status !== 'planned') {
                throw new ManufacturingOperationException("Planned order [{$locked->planned_order_number}] is {$locked->status}.", 'status');
            }

            $order = $this->planning->planOrder(new CreateProductionOrderDTO(
                tenantId: (string) $locked->tenant_id,
                mfgItemId: (string) $locked->mfg_item_id,
                targetQuantity: (string) $locked->quantity,
                plannedStartDate: $locked->start_date->toDateString(),
                plannedEndDate: $locked->due_date->toDateString(),
                bomId: $locked->bom_id,
                routingId: $locked->routing_id,
                plannedOrderId: (string) $locked->id,
                createdBy: $actorUserId,
            ));

            $locked->update([
                'status' => 'converted',
                'converted_order_id' => $order->id,
                'converted_at' => now(),
                'converted_by' => $actorUserId !== null && Str::isUuid($actorUserId) ? $actorUserId : null,
            ]);

            return $order;
        });
    }

    public function markPurchaseSuggestionConverted(MfgMrpPlannedOrder $plannedOrder, string $documentType, string $documentId, ?string $actorUserId = null): MfgMrpPlannedOrder
    {
        return DB::transaction(function () use ($plannedOrder, $documentType, $documentId, $actorUserId): MfgMrpPlannedOrder {
            $locked = MfgMrpPlannedOrder::query()->whereKey($plannedOrder->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isBuy() || $locked->status !== 'planned') {
                throw new ManufacturingOperationException("Planned order [{$locked->planned_order_number}] is not an open purchase suggestion.", 'status');
            }

            $locked->update([
                'status' => 'converted',
                'procurement_document_type' => $documentType,
                'procurement_document_id' => $documentId,
                'converted_at' => now(),
                'converted_by' => $actorUserId !== null && Str::isUuid($actorUserId) ? $actorUserId : null,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Nets one item and writes its planned orders, peggings and shortage; the dependent demand of its make orders is
     * added to the components.
     *
     * @param  list<array{date: string, quantity: string, type: string, reference: string|null}>  $requirements
     * @param  list<array{date: string, quantity: string}>  $scheduled
     * @param  Collection<string, MfgItem>  $items
     * @param  array<string, list<array{date: string, quantity: string, type: string, reference: string|null}>>  $demand
     * @return array{planned: int, shortage: bool}
     */
    private function netItem(MfgMrpRun $run, MfgItem $item, int $level, string $onHand, array $requirements, array $scheduled, CarbonImmutable $start, CarbonImmutable $end, Collection $items, array &$demand): array
    {
        $safety = Money::of($item->safety_stock);
        $projected = $onHand;
        $dates = collect($requirements)->pluck('date')->merge(collect($scheduled)->pluck('date'))->push($start->toDateString())->unique()->sort()->values();
        $grossTotal = collect($requirements)->reduce(static fn (string $sum, array $row): string => Money::add($sum, $row['quantity']), '0');
        $receiptTotal = collect($scheduled)->reduce(static fn (string $sum, array $row): string => Money::add($sum, $row['quantity']), '0');
        $netTotal = '0';
        $planned = 0;
        $firstDue = null;

        foreach ($dates as $date) {
            $projected = Money::add($projected, collect($scheduled)->where('date', $date)->reduce(static fn (string $sum, array $row): string => Money::add($sum, $row['quantity']), '0'));
            $dayDemand = collect($requirements)->where('date', $date)->values();
            $projected = Money::sub($projected, $dayDemand->reduce(static fn (string $sum, array $row): string => Money::add($sum, $row['quantity']), '0'));

            if (Money::compare($projected, $safety) >= 0) {
                continue;
            }

            $shortfall = Money::sub($safety, $projected);
            $netTotal = Money::add($netTotal, $shortfall);
            $firstDue ??= $date;
            $remainingPeg = $dayDemand->all();
            $unpegged = $shortfall;

            foreach ($this->lotSizes($item, $shortfall) as $quantity) {
                $plannedOrder = $this->createPlannedOrder($run, $item, $level, $quantity, $unpegged, CarbonImmutable::parse($date));
                $projected = Money::add($projected, $quantity);
                $planned++;

                $toPeg = Money::min($quantity, $unpegged);
                $unpegged = Money::sub($unpegged, $toPeg);
                foreach ($remainingPeg as $index => $row) {
                    if (! Money::isPositive($toPeg)) {
                        break;
                    }
                    $pegged = Money::min($toPeg, $row['quantity']);
                    MfgMrpPegging::create(['tenant_id' => $run->tenant_id, 'planned_order_id' => $plannedOrder->id, 'demand_type' => $row['type'], 'demand_reference_id' => $row['reference'], 'pegged_quantity' => Money::qty($pegged)]);
                    $toPeg = Money::sub($toPeg, $pegged);
                    $remainingPeg[$index]['quantity'] = Money::sub($row['quantity'], $pegged);
                    if (! Money::isPositive($remainingPeg[$index]['quantity'])) {
                        unset($remainingPeg[$index]);
                    }
                }
                if (Money::isPositive($toPeg)) {
                    MfgMrpPegging::create(['tenant_id' => $run->tenant_id, 'planned_order_id' => $plannedOrder->id, 'demand_type' => 'safety_stock', 'demand_reference_id' => null, 'pegged_quantity' => Money::qty($toPeg)]);
                }

                if ($plannedOrder->isMake() && $plannedOrder->bom_id !== null) {
                    $bom = MfgBom::query()->find($plannedOrder->bom_id);
                    if ($bom instanceof MfgBom) {
                        foreach ($this->boms->componentRequirements($bom, $quantity, $plannedOrder->start_date->toDateString()) as $row) {
                            if ($items->has($row['component_item_id'])) {
                                $demand[$row['component_item_id']][] = ['date' => $this->clamp($plannedOrder->start_date, $start), 'quantity' => $row['effective_quantity'], 'type' => 'planned_order', 'reference' => (string) $plannedOrder->id];
                            }
                        }
                    }
                }
            }
        }

        if ($planned === 0) {
            return ['planned' => 0, 'shortage' => false];
        }

        MfgMrpShortage::create([
            'tenant_id' => $run->tenant_id,
            'mrp_run_id' => $run->id,
            'mfg_item_id' => $item->id,
            'required_date' => $firstDue ?? $start->toDateString(),
            'gross_requirement' => Money::qty($grossTotal),
            'available_inventory' => Money::qty($onHand),
            'scheduled_receipts' => Money::qty($receiptTotal),
            'safety_stock' => Money::qty($safety),
            'net_shortage' => Money::qty($netTotal),
            'recommended_action' => $item->procurementType() === 'buy' ? 'purchase_order' : 'make_order',
        ]);

        return ['planned' => $planned, 'shortage' => true];
    }

    /**
     * Writes a planned order due on the date for the quantity.
     */
    private function createPlannedOrder(MfgMrpRun $run, MfgItem $item, int $level, string $quantity, string $netRequirement, CarbonImmutable $due): MfgMrpPlannedOrder
    {
        $type = $item->procurementType() === 'buy' ? 'buy' : 'make';
        $startDate = $due->subDays(max(0, $item->lead_time_days));
        $bom = $type === 'make' ? $this->boms->effectiveBomFor($item, $startDate->toDateString()) : null;
        $routing = $type === 'make' ? $this->routings->effectiveRoutingFor($item, $startDate->toDateString()) : null;

        return MfgMrpPlannedOrder::create([
            'tenant_id' => $run->tenant_id,
            'planned_order_number' => 'PLN-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
            'mrp_run_id' => $run->id,
            'mfg_item_id' => $item->id,
            'order_type' => $type,
            'quantity' => Money::qty($quantity),
            'net_requirement' => Money::qty(Money::min($netRequirement, $quantity)),
            'bom_level' => $level,
            'uom' => $item->uom,
            'start_date' => $startDate->toDateString(),
            'due_date' => $due->toDateString(),
            'status' => 'planned',
            'bom_id' => $bom?->id,
            'routing_id' => $routing?->id,
        ]);
    }

    /**
     * Order quantities covering a shortfall: at least the minimum lot, rounded up to the multiple, split into lots of
     * at most the maximum.
     *
     * @return list<string>
     */
    private function lotSizes(MfgItem $item, string $shortfall): array
    {
        $quantity = Money::max($shortfall, Money::isPositive($item->min_lot_size) ? $item->min_lot_size : '0');
        $quantity = Money::qty(Money::ceilToMultiple($quantity, $item->lot_size_multiple ?? '0'));
        $max = $item->max_lot_size !== null && Money::isPositive($item->max_lot_size) ? Money::of($item->max_lot_size) : null;

        if ($max === null || Money::compare($quantity, $max) <= 0) {
            return [$quantity];
        }

        $lots = [];
        $remaining = $quantity;
        while (Money::isPositive($remaining) && count($lots) < 1000) {
            $lot = Money::min($remaining, $max);
            $lots[] = Money::qty($lot);
            $remaining = Money::sub($remaining, $lot);
        }

        return $lots;
    }

    /**
     * Low-level code of every item: the deepest level at which it appears in an effective BOM.
     *
     * @param  Collection<string, MfgItem>  $items
     * @return array<string, int>
     */
    private function lowLevelCodes(Collection $items, string $date): array
    {
        $levels = $items->map(static fn (): int => 0)->all();
        $visit = function (MfgItem $item, int $level, array $path) use (&$visit, &$levels, $items, $date): void {
            if ($level > 25 || isset($path[(string) $item->id])) {
                return;
            }
            $bom = $this->boms->effectiveBomFor($item, $date);
            if ($bom === null) {
                return;
            }
            foreach ($this->boms->componentRequirements($bom, '1', $date) as $row) {
                $component = $items->get($row['component_item_id']);
                if (! $component instanceof MfgItem) {
                    continue;
                }
                $levels[(string) $component->id] = max($levels[(string) $component->id] ?? 0, $level + 1);
                $visit($component, $level + 1, [...$path, (string) $item->id => true]);
            }
        };

        foreach ($items as $item) {
            $visit($item, $levels[(string) $item->id] ?? 0, []);
        }

        return $levels;
    }

    /**
     * Open sales order lines and forecasts (less the sales demand of the same month) per item.
     *
     * @param  Collection<string, MfgItem>  $items
     * @return array<string, list<array{date: string, quantity: string, type: string, reference: string|null}>>
     */
    private function independentDemand(string $tenantId, Collection $items, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $byProduct = $this->itemsByProduct($items);
        $demand = [];
        $salesByMonth = [];

        if ($byProduct !== []) {
            $lines = DB::table('sales_order_items as l')
                ->join('sales_orders as o', 'o.id', '=', 'l.sales_order_id')
                ->where('o.tenant_id', $tenantId)
                ->whereNull('o.deleted_at')
                ->whereIn('o.status', self::OpenSalesStatuses)
                ->whereIn('l.product_id', array_keys($byProduct))
                ->get(['l.id', 'l.product_id', 'l.quantity', 'l.quantity_delivered', 'o.due_date', 'o.date']);

            foreach ($lines as $line) {
                $open = Money::sub((string) $line->quantity, (string) ($line->quantity_delivered ?? '0'));
                $date = $this->clamp(CarbonImmutable::parse($line->due_date ?? $line->date), $start);
                if (! Money::isPositive($open) || CarbonImmutable::parse($date)->gt($end)) {
                    continue;
                }

                $itemId = $byProduct[(string) $line->product_id];
                $demand[$itemId][] = ['date' => $date, 'quantity' => Money::qty($open), 'type' => 'sales_order_line', 'reference' => (string) $line->id];
                $month = substr($date, 0, 7);
                $salesByMonth[$itemId][$month] = Money::add($salesByMonth[$itemId][$month] ?? '0', $open);
            }
        }

        $forecasts = MfgDemandForecast::query()->where('tenant_id', $tenantId)->whereIn('mfg_item_id', $items->keys()->all())
            ->whereBetween('forecast_date', [$start->toDateString(), $end->toDateString()])->orderBy('forecast_date')->get();
        foreach ($forecasts as $forecast) {
            $itemId = (string) $forecast->mfg_item_id;
            $month = $forecast->forecast_date->format('Y-m');
            $consumed = Money::min($forecast->quantity, $salesByMonth[$itemId][$month] ?? '0');
            $salesByMonth[$itemId][$month] = Money::sub($salesByMonth[$itemId][$month] ?? '0', $consumed);
            $net = Money::sub($forecast->quantity, $consumed);
            if (Money::isPositive($net)) {
                $demand[$itemId][] = ['date' => $forecast->forecast_date->toDateString(), 'quantity' => Money::qty($net), 'type' => 'forecast', 'reference' => (string) $forecast->id];
            }
        }

        return $demand;
    }

    /**
     * Open production orders: their remaining output is a scheduled receipt, their open components a demand.
     *
     * @param  Collection<string, MfgItem>  $items
     * @param  array<string, list<array{date: string, quantity: string, type: string, reference: string|null}>>  $demand
     * @param  array<string, list<array{date: string, quantity: string}>>  $receipts
     */
    private function openOrders(string $tenantId, Collection $items, CarbonImmutable $start, CarbonImmutable $end, array &$demand, array &$receipts): void
    {
        $orders = MfgProductionOrder::query()->where('tenant_id', $tenantId)->whereIn('status', ['planned', 'released', 'in_progress'])->get();

        foreach ($orders as $order) {
            $remaining = Money::sub($order->target_quantity, $order->completed_quantity);
            $due = $this->clamp($order->planned_end_date, $start);
            if ($items->has((string) $order->mfg_item_id) && Money::isPositive($remaining) && ! CarbonImmutable::parse($due)->gt($end)) {
                $receipts[(string) $order->mfg_item_id][] = ['date' => $due, 'quantity' => Money::qty($remaining)];
            }

            $needed = $this->clamp($order->planned_start_date, $start);
            if ($order->status === 'planned') {
                $bom = MfgBom::query()->find($order->bom_id);
                $rows = $bom instanceof MfgBom && Money::isPositive($remaining) ? $this->boms->componentRequirements($bom, $remaining, $order->planned_start_date->toDateString()) : [];
                foreach ($rows as $row) {
                    if ($items->has($row['component_item_id'])) {
                        $demand[$row['component_item_id']][] = ['date' => $needed, 'quantity' => $row['effective_quantity'], 'type' => 'production_order', 'reference' => (string) $order->id];
                    }
                }

                continue;
            }

            foreach (MfgProductionOrderLine::query()->where('production_order_id', $order->id)->get() as $line) {
                $open = $line->openQuantity();
                if ($items->has((string) $line->mfg_item_id) && Money::isPositive($open)) {
                    $demand[(string) $line->mfg_item_id][] = ['date' => $needed, 'quantity' => Money::qty($open), 'type' => 'production_order', 'reference' => (string) $order->id];
                }
            }
        }
    }

    /**
     * Open lines of sent purchase orders as scheduled receipts.
     *
     * @param  Collection<string, MfgItem>  $items
     * @param  array<string, list<array{date: string, quantity: string}>>  $receipts
     */
    private function openPurchases(string $tenantId, Collection $items, CarbonImmutable $start, CarbonImmutable $end, array &$receipts): void
    {
        $byProduct = $this->itemsByProduct($items);
        if ($byProduct === []) {
            return;
        }

        $lines = DB::table('purchase_order_items as l')
            ->join('purchase_orders as o', 'o.id', '=', 'l.purchase_order_id')
            ->where('o.tenant_id', $tenantId)
            ->whereNull('o.deleted_at')
            ->whereIn('o.status', self::OpenPurchaseStatuses)
            ->whereIn('l.product_id', array_keys($byProduct))
            ->get(['l.product_id', 'l.quantity', 'l.quantity_received', 'o.delivery_due_date', 'o.date']);

        foreach ($lines as $line) {
            $open = Money::sub((string) $line->quantity, (string) ($line->quantity_received ?? '0'));
            $date = $this->clamp(CarbonImmutable::parse($line->delivery_due_date ?? $line->date), $start);
            if (Money::isPositive($open) && ! CarbonImmutable::parse($date)->gt($end)) {
                $receipts[$byProduct[(string) $line->product_id]][] = ['date' => $date, 'quantity' => Money::qty($open)];
            }
        }
    }

    /**
     * Stock per item: on hand in every warehouse less what other documents reserved.
     *
     * @param  Collection<string, MfgItem>  $items
     * @return array<string, string>
     */
    private function onHand(string $tenantId, Collection $items): array
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
            $result[$itemId] = Money::sub((string) ($quants[$stockItemId]->on_hand ?? '0'), (string) ($reserved[$stockItemId]->reserved ?? '0'));
        }

        return $result;
    }

    /**
     * Catalog product id => manufacturing item id, from the item's product or its stock item's product.
     *
     * @param  Collection<string, MfgItem>  $items
     * @return array<string, string>
     */
    private function itemsByProduct(Collection $items): array
    {
        $map = [];
        $stockProducts = StockItem::withoutGlobalScopes()->whereIn('id', $items->pluck('stock_item_id')->filter()->all())->pluck('product_id', 'id');

        foreach ($items as $itemId => $item) {
            $productId = $item->product_id ?? ($item->stock_item_id !== null ? $stockProducts[$item->stock_item_id] ?? null : null);
            if ($productId !== null) {
                $map[(string) $productId] = (string) $itemId;
            }
        }

        return $map;
    }

    /**
     * The date, or the horizon start when it is earlier (past-due demand and receipts count from the start).
     */
    private function clamp(\DateTimeInterface $date, CarbonImmutable $start): string
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        return ($day->lt($start) ? $start : $day)->toDateString();
    }
}
