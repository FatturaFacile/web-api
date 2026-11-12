<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\InventoryCountSession;

/**
 * Rejects an adjustment waiting for approval: nothing is posted. When it carried the differences of a physical count,
 * the count session goes back in progress (still frozen if it froze stock), so the area can be recounted and the
 * differences approved again.
 */
final class RejectInventoryAdjustmentAction
{
    public function execute(InventoryAdjustment $adjustment): InventoryAdjustment
    {
        return DB::transaction(function () use ($adjustment): InventoryAdjustment {
            $locked = InventoryAdjustment::query()->whereKey($adjustment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending_approval') {
                throw ValidationException::withMessages(['status' => __('inventory_errors.adjustment.reject_only_pending', ['status' => $locked->status])]);
            }

            $locked->update(['status' => 'rejected', 'is_locked' => false]);

            InventoryCountSession::query()
                ->where('inventory_adjustment_id', $locked->id)
                ->where('status', 'pending_approval')
                ->update(['status' => 'in_progress', 'inventory_adjustment_id' => null, 'completed_at' => null]);

            return $locked;
        });
    }
}
