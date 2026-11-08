<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Services\Sales\DocumentNumberingService;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\InventoryCountServiceInterface;
use Modules\Inventory\DTOs\InventoryCountSessionDTO;
use Modules\Inventory\Events\InventoryCountCompletedEvent;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\InventoryCountLine;
use Modules\Inventory\Models\InventoryCountSession;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Support\Quantity;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;

/**
 * Physical inventory and cycle counts.
 *
 * A session snapshots the system quantity of every quant it covers (today's, or the stock at a past moment for a
 * retroactive stocktake) and may freeze the counted area (InventoryFreezeGuard). Counters record what they find;
 * a line can be sent back for a recount, keeping the first count. Approving the differences turns every counted line
 * that differs into an adjustment line valued at the item's moving average cost (standard cost when it has none):
 * within the approval threshold it is posted at once, above it the INVENTORY_WRITE_OFF_APPROVAL workflow decides and
 * the session stays pending (and frozen) until then. Posting goes through InventoryAdjustmentPoster: stock movements
 * plus the cost impact to the General Ledger.
 *
 * Statuses: in_progress -> pending_approval -> reconciled; in_progress or pending_approval -> cancelled.
 */
final class InventoryCountService implements InventoryCountServiceInterface
{
    /** Statuses in which a session is still open: counts may change and its freeze holds. */
    public const array OPEN_STATUSES = ['in_progress', 'pending_approval'];

    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly WorkflowEngineInterface $workflowEngine,
        private readonly StockBalanceAtDateService $balances,
        private readonly InventoryAdjustmentPoster $poster,
        private readonly DocumentNumberingService $numbering
    ) {}

    /**
     * Opens a session and creates one line per quant it covers. The number comes from the gapless document numbering
     * (`inventory_count`); a number given in the DTO is ignored.
     */
    public function createSession(InventoryCountSessionDTO $dto): InventoryCountSession
    {
        return DB::transaction(function () use ($dto): InventoryCountSession {
            $warehouse = Warehouse::findOrFail($dto->warehouseId);

            $session = InventoryCountSession::create([
                'tenant_id' => $warehouse->tenant_id,
                'company_id' => $warehouse->company_id,
                'warehouse_id' => $warehouse->id,
                'session_number' => $this->numbering->next('inventory_count', null, (string) $warehouse->tenant_id)['number'],
                'count_type' => $dto->countType,
                'status' => 'in_progress',
                'scheduled_date' => $dto->scheduledDate,
                'effective_at' => $dto->effectiveAt,
                'warehouse_zone_id' => $dto->zoneId,
                'notes' => $dto->notes,
                'is_locked' => false,
                'freezes_stock' => $dto->freezesStock,
                'frozen_at' => $dto->freezesStock ? Carbon::now() : null,
                'cycle_count_plan_id' => $dto->planId,
                'created_by' => $dto->userId,
            ]);

            $query = StockQuant::query()
                ->whereHas('bin.rack.aisle.zone', fn ($q) => $q->where('warehouse_id', $warehouse->id))
                ->with(['bin', 'item']);

            if ($dto->binIds !== []) {
                $query->whereIn('warehouse_bin_id', $dto->binIds);
            }

            if ($dto->stockItemIds !== []) {
                $query->whereIn('stock_item_id', $dto->stockItemIds);
            }

            if ($dto->zoneId !== null) {
                $query->whereHas('bin.rack.aisle', static fn ($aisle) => $aisle->where('warehouse_zone_id', $dto->zoneId));
            }

            $balances = $dto->effectiveAt !== null ? $this->balances->balances($warehouse->id, Carbon::parse($dto->effectiveAt)) : null;

            foreach ($query->orderBy('id')->get() as $quant) {
                $systemQuantity = $balances === null
                    ? (string) $quant->quantity_on_hand
                    : ($balances->get(StockBalanceAtDateService::key((string) $quant->warehouse_bin_id, (string) $quant->stock_item_id, $quant->lot_id))['quantity'] ?? '0');

                InventoryCountLine::create([
                    'tenant_id' => $session->tenant_id,
                    'inventory_count_session_id' => $session->id,
                    'warehouse_bin_id' => $quant->warehouse_bin_id,
                    'stock_item_id' => $quant->stock_item_id,
                    'lot_id' => $quant->lot_id,
                    'system_quantity' => Quantity::of($systemQuantity),
                    'counted_quantity' => null,
                    'discrepancy_quantity' => '0',
                    'discrepancy_valuation' => '0',
                ]);
            }

            return $session;
        });
    }

    /**
     * Records a count: the difference with the system quantity and its value at the item's unit cost.
     *
     * @throws StockRuleViolation when the line is not of the given session or the session is closed
     */
    public function recordCount(string $sessionLineId, float|string $countedQuantity, string $userId, ?string $sessionId = null): void
    {
        DB::transaction(function () use ($sessionLineId, $countedQuantity, $userId, $sessionId): void {
            /** @var InventoryCountLine $line */
            $line = InventoryCountLine::query()->with(['item', 'session'])->whereKey($sessionLineId)->lockForUpdate()->firstOrFail();

            if ($sessionId !== null && (string) $line->inventory_count_session_id !== $sessionId) {
                throw new StockRuleViolation('The count line does not belong to this count session.');
            }

            if ($line->session !== null && $line->session->status !== 'in_progress') {
                throw new StockRuleViolation("The count session is {$line->session->status}: counts can no longer change.");
            }

            $counted = Quantity::of($countedQuantity);
            $delta = Quantity::sub($counted, (string) $line->system_quantity);

            $line->update([
                'counted_quantity' => $counted,
                'discrepancy_quantity' => $delta,
                'discrepancy_valuation' => Quantity::value($delta, $this->unitCost($line->item)),
                'counted_by_user_id' => $userId,
                'counted_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Sends counted lines back for a second count: the first count is kept in `first_counted_quantity`, the line is
     * counted again from scratch.
     *
     * @param  list<string>  $lineIds
     * @return int the number of lines sent back
     */
    public function requestRecount(InventoryCountSession $session, array $lineIds): int
    {
        return DB::transaction(function () use ($session, $lineIds): int {
            $locked = InventoryCountSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'in_progress') {
                throw new StockRuleViolation("The count session is {$locked->status}: no recount can be requested.");
            }

            $lines = InventoryCountLine::query()->where('inventory_count_session_id', $locked->id)->whereIn('id', $lineIds)->lockForUpdate()->get();

            if ($lines->count() !== count(array_unique($lineIds))) {
                throw new StockRuleViolation('Every line to recount must belong to this count session.');
            }

            foreach ($lines as $line) {
                if ($line->counted_quantity === null) {
                    throw new StockRuleViolation('Only counted lines can be sent back for a recount.');
                }

                $line->update([
                    'first_counted_quantity' => $line->first_counted_quantity ?? $line->counted_quantity,
                    'counted_quantity' => null,
                    'discrepancy_quantity' => '0',
                    'discrepancy_valuation' => '0',
                    'counted_by_user_id' => null,
                    'counted_at' => null,
                    'recount_count' => $line->recount_count + 1,
                    'recount_requested_at' => Carbon::now(),
                ]);
            }

            return $lines->count();
        });
    }

    /**
     * Approves the differences of the session (see the class description). Uncounted lines are not adjusted.
     *
     * @throws StockRuleViolation when nothing was counted or the session is not in progress
     */
    public function reconcileSession(InventoryCountSession $session, string $userId): InventoryAdjustment
    {
        return DB::transaction(function () use ($session, $userId): InventoryAdjustment {
            $session = InventoryCountSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($session->status !== 'in_progress') {
                throw new StockRuleViolation("The count session is {$session->status}: only a session in progress can be approved.");
            }

            $counted = $session->lines()->with('item')->whereNotNull('counted_quantity')->orderBy('id')->get();

            if ($counted->isEmpty()) {
                throw new StockRuleViolation('Nothing was counted in this session.');
            }

            $lines = $counted
                ->filter(static fn (InventoryCountLine $line): bool => ! Quantity::isZero((string) $line->discrepancy_quantity))
                ->map(fn (InventoryCountLine $line): array => [
                    'stock_item_id' => (string) $line->stock_item_id,
                    'warehouse_bin_id' => (string) $line->warehouse_bin_id,
                    'lot_id' => $line->lot_id,
                    'quantity_delta' => Quantity::of((string) $line->discrepancy_quantity),
                    'unit_cost' => $this->unitCost($line->item),
                ])
                ->values()
                ->all();

            $net = $this->poster->netValue($lines);
            $requiresApproval = Quantity::compare(Quantity::abs($net, 2), (string) config('inventory.adjustment_approval_threshold')) > 0;

            $adjustment = InventoryAdjustment::create([
                'tenant_id' => $session->tenant_id,
                'company_id' => $session->company_id,
                'warehouse_id' => $session->warehouse_id,
                'adjustment_number' => $this->numbering->next('inventory_adjustment', null, (string) $session->tenant_id)['number'],
                'reason' => 'cycle_count_variance',
                'status' => $requiresApproval ? 'pending_approval' : 'approved',
                'net_valuation_change' => $net,
                'is_locked' => $requiresApproval,
                'created_by' => $userId,
                'approved_by' => $requiresApproval ? null : $userId,
                'approved_at' => $requiresApproval ? null : Carbon::now(),
            ]);

            if ($requiresApproval) {
                $this->workflowEngine->start(new StartWorkflowDTO(
                    workflowCode: 'INVENTORY_WRITE_OFF_APPROVAL',
                    approvable: $adjustment,
                    contextSnapshot: [
                        'session_id' => (string) $session->id,
                        'session_number' => $session->session_number,
                        'variance_amount' => $net,
                        'discrepancy_count' => count($lines),
                        'lines' => $lines,
                    ],
                    tenantId: (string) $adjustment->tenant_id,
                    companyId: $adjustment->company_id !== null ? (string) $adjustment->company_id : null,
                    initiatorUserId: $userId
                ));
            } else {
                $this->poster->post($adjustment, $lines, $userId, InventoryCountSession::class, (string) $session->id, $session->effective_at?->toIso8601String());
            }

            $session->update([
                'status' => $requiresApproval ? 'pending_approval' : 'reconciled',
                'completed_at' => Carbon::now(),
                'inventory_adjustment_id' => $adjustment->id,
                'approved_by' => $requiresApproval ? null : $userId,
                'approved_at' => $requiresApproval ? null : Carbon::now(),
            ]);

            $this->dispatcher->publishAndDispatch(new InventoryCountCompletedEvent(
                sessionNumber: $session->session_number,
                warehouseId: $session->warehouse_id,
                totalValuationChange: (float) $net,
                status: $session->status,
                tenantId: $session->tenant_id,
                companyId: (string) ($session->company_id ?? ''),
                sessionId: (string) $session->id
            ));

            return $adjustment;
        });
    }

    /**
     * Cancels an open session: nothing is posted and its stock freeze ends.
     */
    public function cancelSession(InventoryCountSession $session): InventoryCountSession
    {
        return DB::transaction(function () use ($session): InventoryCountSession {
            $locked = InventoryCountSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'in_progress') {
                throw new StockRuleViolation("The count session is {$locked->status}: only a session in progress can be cancelled.");
            }

            $locked->update(['status' => 'cancelled', 'completed_at' => Carbon::now()]);

            return $locked;
        });
    }

    /**
     * The unit cost differences are valued at: the moving average cost, or the standard cost when the item has none.
     */
    private function unitCost(?StockItem $item): string
    {
        if ($item === null) {
            return Quantity::of(0);
        }

        $movingAverage = Quantity::of((string) $item->current_moving_average_cost);

        return Quantity::isPositive($movingAverage) ? $movingAverage : Quantity::of((string) $item->standard_cost);
    }
}
