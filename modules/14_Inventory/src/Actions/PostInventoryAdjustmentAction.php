<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Enterprise\EventBus\Contracts\EventBusInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Events\StockAdjustedEvent;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\InventoryCountSession;
use Modules\Inventory\Services\InventoryAdjustmentPoster;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * Approves an adjustment that waited for approval: moves the stock of the lines that were sent for approval (the
 * context of its approval workflow) and announces the cost impact to the General Ledger (InventoryAdjustmentPosted
 * Event). The approver cannot change the lines: what is approved is exactly what was submitted.
 *
 * When the adjustment carries the differences of a physical count, the count session is closed as approved and its
 * movements are attributed to the session (so its own stock freeze does not block them).
 */
final readonly class PostInventoryAdjustmentAction
{
    public function __construct(
        private InventoryAdjustmentPoster $poster,
        private EventBusInterface $eventBus
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $lines  ignored: kept for compatibility, the submitted lines are posted
     */
    public function execute(InventoryAdjustment $adjustment, array $lines, string $approvedBy): InventoryAdjustment
    {
        return DB::transaction(function () use ($adjustment, $approvedBy): InventoryAdjustment {
            $adjustment = InventoryAdjustment::query()->whereKey($adjustment->getKey())->lockForUpdate()->firstOrFail();

            if ($adjustment->status !== 'pending_approval') {
                throw ValidationException::withMessages(['status' => "The adjustment is {$adjustment->status}: only a pending adjustment can be approved."]);
            }

            ['lines' => $lines, 'session_id' => $sessionId] = $this->submission($adjustment);
            $session = $sessionId !== null ? InventoryCountSession::query()->find($sessionId) : null;

            $adjustment->update([
                'status' => 'approved',
                'is_locked' => false,
                'approved_by' => $approvedBy,
                'approved_at' => Carbon::now(),
            ]);

            $this->poster->post(
                $adjustment,
                $lines,
                $approvedBy,
                $session !== null ? InventoryCountSession::class : null,
                $session?->id !== null ? (string) $session->id : null,
                $session?->effective_at?->toIso8601String()
            );

            if ($session !== null) {
                $session->update(['status' => 'reconciled', 'approved_by' => $approvedBy, 'approved_at' => Carbon::now(), 'is_locked' => false]);
            }

            $this->eventBus->dispatch(new StockAdjustedEvent(
                adjustmentId: (string) $adjustment->id,
                warehouseId: (string) $adjustment->warehouse_id,
                netValuationChange: (float) $adjustment->net_valuation_change,
                status: 'approved',
                companyId: (string) ($adjustment->company_id ?? 'default'),
                userId: $approvedBy,
                tenantId: (string) $adjustment->tenant_id
            ));

            return $adjustment;
        });
    }

    /**
     * The lines sent for approval and the count session they come from, read from the approval workflow context.
     *
     * @return array{lines: array<int, array{stock_item_id: string, warehouse_bin_id: string, quantity_delta: float|int|string, unit_cost: float|int|string, lot_id?: ?string}>, session_id: ?string}
     */
    private function submission(InventoryAdjustment $adjustment): array
    {
        $snapshot = WorkflowInstance::withoutGlobalScopes()
            ->where('approvable_type', InventoryAdjustment::class)
            ->where('approvable_id', (string) $adjustment->id)
            ->latest('created_at')
            ->value('context_snapshot');

        $snapshot = is_string($snapshot) ? json_decode($snapshot, true) : $snapshot;

        return [
            'lines' => is_array($snapshot['lines'] ?? null) ? array_values($snapshot['lines']) : [],
            'session_id' => isset($snapshot['session_id']) ? (string) $snapshot['session_id'] : null,
        ];
    }
}
