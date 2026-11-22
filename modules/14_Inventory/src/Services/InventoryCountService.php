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
use Modules\Inventory\Models\InventoryCountApproval;
use Modules\Inventory\Models\InventoryCountEntry;
use Modules\Inventory\Models\InventoryCountEvent;
use Modules\Inventory\Models\InventoryCountLine;
use Modules\Inventory\Models\InventoryCountPolicy;
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
 * A session snapshots the system quantity and unit cost of every quant it covers (today's, or the stock at a past
 * moment for a retroactive stocktake) and may freeze the counted area (InventoryFreezeGuard): the whole warehouse, one
 * zone or only the counted bins. Counters record what they find, blind by default (the count sheet never carries the
 * system quantity, see InventoryCountLineResource). A line is sent back for a recount by a supervisor, or
 * automatically when its variance exceeds the recount thresholds of the tenant's InventoryCountPolicy; the first count
 * is kept and every round is stored as an InventoryCountEntry.
 *
 * Approving the differences turns every counted line that differs into an adjustment line valued at the snapshot unit
 * cost (the item's moving average cost, standard cost when it has none). Two approval modes (policy.approval_mode):
 * - `workflow` (default): within the approval threshold (config) the adjustment is posted at once, above it the
 *   INVENTORY_WRITE_OFF_APPROVAL workflow decides and the session stays pending (and frozen) until then;
 * - `multi_level`: CountVarianceMath::approvalLevel decides: within tolerance the adjustment is posted at once (or
 *   needs the warehouse manager when the policy does not auto-approve), level 1 needs the warehouse manager, level 2
 *   the warehouse manager and then the finance approver, with segregation of duties and a mandatory reason on rejection.
 * Posting goes through InventoryAdjustmentPoster: stock movements plus the cost impact to the General Ledger.
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
            $policy = InventoryCountPolicy::forTenant((string) $warehouse->tenant_id);
            $now = Carbon::now();

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
                'is_blind' => $dto->isBlind ?? $policy->blind_by_default,
                'freezes_stock' => $dto->freezesStock,
                'freeze_scope' => match (true) {
                    $dto->binIds !== [] => 'bins',
                    $dto->zoneId !== null => 'zone',
                    default => 'warehouse',
                },
                'frozen_at' => $dto->freezesStock ? $now : null,
                'snapshot_at' => $dto->effectiveAt !== null ? Carbon::parse($dto->effectiveAt) : $now,
                'assigned_to' => $dto->assignedTo,
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
                $systemQuantity = Quantity::of($balances === null
                    ? (string) $quant->quantity_on_hand
                    : ($balances->get(StockBalanceAtDateService::key((string) $quant->warehouse_bin_id, (string) $quant->stock_item_id, $quant->lot_id))['quantity'] ?? '0'));
                $unitCost = $this->unitCost($quant->item);

                InventoryCountLine::create([
                    'tenant_id' => $session->tenant_id,
                    'inventory_count_session_id' => $session->id,
                    'warehouse_bin_id' => $quant->warehouse_bin_id,
                    'stock_item_id' => $quant->stock_item_id,
                    'lot_id' => $quant->lot_id,
                    'system_quantity' => $systemQuantity,
                    'unit_cost' => $unitCost,
                    'system_value' => Quantity::value($systemQuantity, $unitCost),
                    'counted_quantity' => null,
                    'discrepancy_quantity' => '0',
                    'discrepancy_valuation' => '0',
                ]);
            }

            $this->audit($session, 'session_opened', $dto->userId, ['freezes_stock' => $dto->freezesStock, 'is_blind' => $session->is_blind]);

            return $session;
        });
    }

    /**
     * Records a count: the difference with the system quantity (and its percentage) and its value at the snapshot unit
     * cost. A resend with a known `clientEntryId` (an offline device syncing again) changes nothing. When the variance
     * exceeds the recount thresholds of the policy (and the rounds are not used up) the line goes back for a recount.
     *
     * @throws StockRuleViolation when the line is not of the given session or the session is closed
     */
    public function recordCount(string $sessionLineId, float|string $countedQuantity, string $userId, ?string $sessionId = null, ?string $clientEntryId = null, ?string $notes = null, ?string $deviceId = null): InventoryCountLine
    {
        return DB::transaction(function () use ($sessionLineId, $countedQuantity, $userId, $sessionId, $clientEntryId, $notes, $deviceId): InventoryCountLine {
            /** @var InventoryCountLine $line */
            $line = InventoryCountLine::query()->with(['item', 'session'])->whereKey($sessionLineId)->lockForUpdate()->firstOrFail();

            if ($sessionId !== null && (string) $line->inventory_count_session_id !== $sessionId) {
                throw new StockRuleViolation('The count line does not belong to this count session.');
            }

            if ($line->session !== null && $line->session->status !== 'in_progress') {
                throw new StockRuleViolation("The count session is {$line->session->status}: counts can no longer change.");
            }

            if ($clientEntryId !== null && InventoryCountEntry::query()->where('client_entry_id', $clientEntryId)->exists()) {
                return $line;
            }

            $counted = Quantity::of($countedQuantity);
            $delta = Quantity::sub($counted, (string) $line->system_quantity);
            $valuation = Quantity::value($delta, $this->lineUnitCost($line));
            $percentage = CountVarianceMath::percentage((string) $line->system_quantity, $counted);

            $line->update([
                'counted_quantity' => $counted,
                'discrepancy_quantity' => $delta,
                'discrepancy_valuation' => $valuation,
                'variance_percentage' => $percentage,
                'counted_by_user_id' => $userId,
                'counted_at' => Carbon::now(),
                'recount_required' => false,
                'recount_reason' => null,
                'count_notes' => $notes ?? $line->count_notes,
            ]);

            InventoryCountEntry::create([
                'tenant_id' => $line->tenant_id,
                'inventory_count_session_id' => $line->inventory_count_session_id,
                'inventory_count_line_id' => $line->id,
                'round' => $line->recount_count,
                'counted_quantity' => $counted,
                'counted_by' => $userId,
                'counted_at' => Carbon::now(),
                'client_entry_id' => $clientEntryId,
                'device_id' => $deviceId,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            $policy = InventoryCountPolicy::forTenant((string) $line->tenant_id);

            if (! Quantity::isZero($delta) && CountVarianceMath::recountDue($policy, $valuation, $percentage, Quantity::isZero((string) $line->system_quantity), $line->recount_count)) {
                $this->sendBack($line, 'variance');
                $this->audit($line->session, 'recount_requested', $userId, ['reason' => 'variance', 'round' => $line->recount_count], (string) $line->id);
            }

            return $line->refresh();
        });
    }

    /**
     * Sends counted lines back for a second count: the first count is kept in `first_counted_quantity`, the line is
     * counted again from scratch (blind: the previous count is not shown unless the policy reveals it).
     *
     * @param  list<string>  $lineIds
     * @return int the number of lines sent back
     */
    public function requestRecount(InventoryCountSession $session, array $lineIds, ?string $userId = null): int
    {
        return DB::transaction(function () use ($session, $lineIds, $userId): int {
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

                $this->sendBack($line, 'supervisor');
                $this->audit($locked, 'recount_requested', $userId, ['reason' => 'supervisor', 'round' => $line->recount_count], (string) $line->id);
            }

            return $lines->count();
        });
    }

    /**
     * Approves the differences of the session (see the class description). Uncounted lines are not adjusted.
     *
     * @throws StockRuleViolation when nothing was counted, a recount is pending or the session is not in progress
     */
    public function reconcileSession(InventoryCountSession $session, string $userId): InventoryAdjustment
    {
        return DB::transaction(function () use ($session, $userId): InventoryAdjustment {
            $session = InventoryCountSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($session->status !== 'in_progress') {
                throw new StockRuleViolation("The count session is {$session->status}: only a session in progress can be approved.");
            }

            if ($session->lines()->where('recount_required', true)->exists()) {
                throw new StockRuleViolation('Lines are waiting for a recount: count them again before approving the differences.');
            }

            $counted = $session->lines()->with('item')->whereNotNull('counted_quantity')->orderBy('id')->get();

            if ($counted->isEmpty()) {
                throw new StockRuleViolation('Nothing was counted in this session.');
            }

            $differing = $counted->filter(static fn (InventoryCountLine $line): bool => ! Quantity::isZero((string) $line->discrepancy_quantity));
            $lines = $this->adjustmentLines($differing);
            $net = $this->poster->netValue($lines);

            $policy = InventoryCountPolicy::forTenant((string) $session->tenant_id);
            $level = $policy->isMultiLevel()
                ? CountVarianceMath::approvalLevel($policy, $differing->map(static fn (InventoryCountLine $line): array => [
                    'variance_value' => (string) $line->discrepancy_valuation,
                    'variance_percentage' => $line->variance_percentage === null ? null : (string) $line->variance_percentage,
                    'variance_quantity' => (string) $line->discrepancy_quantity,
                ])->values()->all())
                : null;

            $approvalLevels = match (true) {
                $level === null => 0,
                $level === 0 => ($policy->auto_approve_within_tolerance || $lines === []) ? 0 : 1,
                default => $level,
            };

            $requiresApproval = $level === null
                ? Quantity::compare(Quantity::abs($net, 2), (string) config('inventory.adjustment_approval_threshold')) > 0
                : $approvalLevels > 0;

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

            if ($requiresApproval && $level !== null) {
                foreach (range(1, $approvalLevels) as $approvalLevel) {
                    InventoryCountApproval::create([
                        'tenant_id' => $session->tenant_id,
                        'inventory_count_session_id' => $session->id,
                        'inventory_adjustment_id' => $adjustment->id,
                        'level' => $approvalLevel,
                        'required_role' => $approvalLevel === 1 ? 'warehouse_manager' : 'finance',
                        'status' => InventoryCountApproval::PENDING,
                        'created_by' => $userId,
                    ]);
                }
            } elseif ($requiresApproval) {
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
                'approval_level_required' => $level === null ? null : $approvalLevels,
                'approval_mode' => $policy->approval_mode,
            ]);

            $this->audit($session, 'differences_submitted', $userId, ['net_value' => $net, 'approval_levels' => $approvalLevels, 'adjustment_id' => (string) $adjustment->id]);
            $this->announceCompleted($session, $net);

            return $adjustment;
        });
    }

    /**
     * Approves one level of the differences of a session in multi-level mode. Level 2 waits for level 1. When the last
     * level is approved the adjustment is posted (stock movements and General Ledger) and the session is reconciled.
     *
     * @throws StockRuleViolation out of sequence, already decided, or against segregation of duties
     */
    public function approveLevel(InventoryCountSession $session, int $level, string $userId, ?string $notes = null): InventoryCountSession
    {
        return DB::transaction(function () use ($session, $level, $userId, $notes): InventoryCountSession {
            [$locked, $adjustment, $approval] = $this->pendingApproval($session, $level, $userId);

            $approval->update(['status' => InventoryCountApproval::APPROVED, 'decided_by' => $userId, 'decided_at' => Carbon::now(), 'notes' => $notes]);
            $this->audit($locked, 'level_approved', $userId, ['level' => $level, 'notes' => $notes]);

            $stillPending = InventoryCountApproval::query()->where('inventory_adjustment_id', $adjustment->id)->where('status', InventoryCountApproval::PENDING)->exists();

            if ($stillPending) {
                return $locked->refresh();
            }

            $lines = $this->adjustmentLines(
                $locked->lines()->with('item')->whereNotNull('counted_quantity')->orderBy('id')->get()
                    ->filter(static fn (InventoryCountLine $line): bool => ! Quantity::isZero((string) $line->discrepancy_quantity))
            );

            $adjustment->update(['status' => 'approved', 'is_locked' => false, 'approved_by' => $userId, 'approved_at' => Carbon::now()]);
            $this->poster->post($adjustment, $lines, $userId, InventoryCountSession::class, (string) $locked->id, $locked->effective_at?->toIso8601String());
            $locked->update(['status' => 'reconciled', 'is_locked' => false, 'approved_by' => $userId, 'approved_at' => Carbon::now()]);
            $this->audit($locked, 'differences_posted', $userId, ['adjustment_id' => (string) $adjustment->id]);
            $this->announceCompleted($locked->refresh(), (string) $adjustment->net_valuation_change);

            return $locked;
        });
    }

    /**
     * Rejects a level: nothing is posted, the adjustment is rejected and the session goes back in progress (still
     * frozen when it froze stock) so the area can be recounted and the differences submitted again.
     *
     * @throws StockRuleViolation without a reason, out of sequence, already decided, or against segregation of duties
     */
    public function rejectLevel(InventoryCountSession $session, int $level, string $userId, string $reason): InventoryCountSession
    {
        if (trim($reason) === '') {
            throw new StockRuleViolation('A rejection needs a reason.');
        }

        return DB::transaction(function () use ($session, $level, $userId, $reason): InventoryCountSession {
            [$locked, $adjustment, $approval] = $this->pendingApproval($session, $level, $userId);

            $approval->update(['status' => InventoryCountApproval::REJECTED, 'decided_by' => $userId, 'decided_at' => Carbon::now(), 'notes' => $reason]);
            InventoryCountApproval::query()->where('inventory_adjustment_id', $adjustment->id)->where('status', InventoryCountApproval::PENDING)->update(['status' => 'cancelled']);
            $adjustment->update(['status' => 'rejected', 'is_locked' => false]);
            $locked->update(['status' => 'in_progress', 'is_locked' => false, 'inventory_adjustment_id' => null, 'completed_at' => null, 'approval_level_required' => null]);
            $this->audit($locked, 'level_rejected', $userId, ['level' => $level, 'reason' => $reason, 'adjustment_id' => (string) $adjustment->id]);

            return $locked;
        });
    }

    /**
     * Freezes the stock of an open session from now on (no movement may touch the counted area).
     */
    public function freeze(InventoryCountSession $session, string $userId): InventoryCountSession
    {
        return $this->changeFreeze($session, true, $userId, null);
    }

    /**
     * Releases the freeze of an open session before it ends: an administrative override, kept in the audit log with its
     * reason. The snapshot quantities no longer track the stock after this, so the count should be redone.
     *
     * @throws StockRuleViolation without a reason
     */
    public function unfreeze(InventoryCountSession $session, string $userId, string $reason): InventoryCountSession
    {
        if (trim($reason) === '') {
            throw new StockRuleViolation('Releasing a stock freeze needs a reason.');
        }

        return $this->changeFreeze($session, false, $userId, $reason);
    }

    /**
     * Assigns the session to the user who counts it.
     */
    public function assign(InventoryCountSession $session, ?string $assigneeId, string $userId): InventoryCountSession
    {
        $session->update(['assigned_to' => $assigneeId]);
        $this->audit($session, 'assigned', $userId, ['assigned_to' => $assigneeId]);

        return $session;
    }

    /**
     * Cancels an open session: nothing is posted and its stock freeze ends.
     */
    public function cancelSession(InventoryCountSession $session, ?string $userId = null): InventoryCountSession
    {
        return DB::transaction(function () use ($session, $userId): InventoryCountSession {
            $locked = InventoryCountSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'in_progress') {
                throw new StockRuleViolation("The count session is {$locked->status}: only a session in progress can be cancelled.");
            }

            $locked->update(['status' => 'cancelled', 'completed_at' => Carbon::now()]);
            $this->audit($locked, 'session_cancelled', $userId);

            return $locked;
        });
    }

    private function changeFreeze(InventoryCountSession $session, bool $freeze, string $userId, ?string $reason): InventoryCountSession
    {
        return DB::transaction(function () use ($session, $freeze, $userId, $reason): InventoryCountSession {
            $locked = InventoryCountSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, self::OPEN_STATUSES, true)) {
                throw new StockRuleViolation("The count session is {$locked->status}: its freeze can no longer change.");
            }

            $locked->update($freeze ? ['freezes_stock' => true, 'frozen_at' => Carbon::now()] : ['freezes_stock' => false]);
            $this->audit($locked, $freeze ? 'stock_frozen' : 'stock_unfrozen', $userId, $reason === null ? [] : ['reason' => $reason]);

            return $locked;
        });
    }

    /**
     * The adjustment, the session and the pending approval of a level, after the sequence, status and segregation of
     * duties checks.
     *
     * @return array{0: InventoryCountSession, 1: InventoryAdjustment, 2: InventoryCountApproval}
     */
    private function pendingApproval(InventoryCountSession $session, int $level, string $userId): array
    {
        $locked = InventoryCountSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== 'pending_approval' || $locked->approval_mode !== InventoryCountPolicy::MODE_MULTI_LEVEL || $locked->inventory_adjustment_id === null) {
            throw new StockRuleViolation('This count is not waiting for a multi-level approval.');
        }

        /** @var InventoryAdjustment $adjustment */
        $adjustment = InventoryAdjustment::query()->whereKey($locked->inventory_adjustment_id)->lockForUpdate()->firstOrFail();
        $approvals = InventoryCountApproval::query()->where('inventory_adjustment_id', $adjustment->id)->orderBy('level')->get()->keyBy('level');
        $approval = $approvals->get($level);

        if ($approval === null) {
            throw new StockRuleViolation("This count does not need an approval at level {$level}.");
        }

        if ($approval->status !== InventoryCountApproval::PENDING) {
            throw new StockRuleViolation("Level {$level} was already decided ({$approval->status}).");
        }

        if ($level > 1 && $approvals->get($level - 1)?->status !== InventoryCountApproval::APPROVED) {
            throw new StockRuleViolation('The lower approval level must be approved first.');
        }

        if (InventoryCountPolicy::forTenant((string) $locked->tenant_id)->enforce_segregation_of_duties) {
            if ((string) $adjustment->created_by === $userId) {
                throw new StockRuleViolation('Segregation of duties: whoever submitted the differences cannot approve them.');
            }

            if ($approvals->contains(static fn (InventoryCountApproval $other): bool => $other->level !== $level && (string) $other->decided_by === $userId)) {
                throw new StockRuleViolation('Segregation of duties: each approval level needs a different person.');
            }
        }

        return [$locked, $adjustment, $approval];
    }

    /**
     * Keeps the first count, resets the line and flags it as waiting for a recount.
     */
    private function sendBack(InventoryCountLine $line, string $reason): void
    {
        $line->update([
            'first_counted_quantity' => $line->first_counted_quantity ?? $line->counted_quantity,
            'counted_quantity' => null,
            'discrepancy_quantity' => '0',
            'discrepancy_valuation' => '0',
            'variance_percentage' => null,
            'counted_by_user_id' => null,
            'counted_at' => null,
            'recount_count' => $line->recount_count + 1,
            'recount_requested_at' => Carbon::now(),
            'recount_required' => true,
            'recount_reason' => $reason,
        ]);
    }

    /**
     * @param  iterable<int, InventoryCountLine>  $differing
     * @return list<array{stock_item_id: string, warehouse_bin_id: string, lot_id: ?string, quantity_delta: string, unit_cost: string}>
     */
    private function adjustmentLines(iterable $differing): array
    {
        $lines = [];

        foreach ($differing as $line) {
            $lines[] = [
                'stock_item_id' => (string) $line->stock_item_id,
                'warehouse_bin_id' => (string) $line->warehouse_bin_id,
                'lot_id' => $line->lot_id,
                'quantity_delta' => Quantity::of((string) $line->discrepancy_quantity),
                'unit_cost' => $this->lineUnitCost($line),
            ];
        }

        return $lines;
    }

    private function announceCompleted(InventoryCountSession $session, string $net): void
    {
        $this->dispatcher->publishAndDispatch(new InventoryCountCompletedEvent(
            sessionNumber: $session->session_number,
            warehouseId: $session->warehouse_id,
            totalValuationChange: (float) $net,
            status: $session->status,
            tenantId: $session->tenant_id,
            companyId: (string) ($session->company_id ?? ''),
            sessionId: (string) $session->id
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audit(?InventoryCountSession $session, string $event, ?string $userId, array $payload = [], ?string $lineId = null): void
    {
        if ($session === null) {
            return;
        }

        InventoryCountEvent::create([
            'tenant_id' => $session->tenant_id,
            'inventory_count_session_id' => $session->id,
            'inventory_count_line_id' => $lineId,
            'event' => $event,
            'user_id' => $userId,
            'payload' => $payload === [] ? null : $payload,
            'created_by' => $userId,
        ]);
    }

    /**
     * The unit cost a line is valued at: the snapshot taken when the session opened, else the item's current cost.
     */
    private function lineUnitCost(InventoryCountLine $line): string
    {
        return $line->unit_cost !== null ? Quantity::of((string) $line->unit_cost) : $this->unitCost($line->item);
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
