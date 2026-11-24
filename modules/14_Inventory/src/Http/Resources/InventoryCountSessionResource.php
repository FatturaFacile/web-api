<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\InventoryCountPolicy;
use Modules\Inventory\Models\InventoryCountSession;

/**
 * A physical count session with its freeze, approval and adjustment. Its lines are serialised by
 * InventoryCountLineResource and are blind (no system quantity) while the session is blind, whoever asks: the
 * theoretical figures are served by the variance endpoint to the people who may see them.
 *
 * @mixin InventoryCountSession
 */
final class InventoryCountSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $revealFirstCount = ! $this->is_blind || InventoryCountPolicy::forTenant((string) $this->tenant_id)->reveal_first_count_on_recount;

        return [
            'id' => $this->id,
            'session_number' => $this->session_number,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_zone_id' => $this->warehouse_zone_id,
            'count_type' => $this->count_type,
            'status' => $this->status,
            'scheduled_date' => $this->scheduled_date?->toDateString(),
            'effective_at' => $this->effective_at?->toIso8601String(),
            'snapshot_at' => $this->snapshot_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'is_locked' => (bool) $this->is_locked,
            'is_blind' => (bool) $this->is_blind,
            'freezes_stock' => (bool) $this->freezes_stock,
            'freeze_scope' => $this->freeze_scope,
            'frozen_at' => $this->frozen_at?->toIso8601String(),
            'assigned_to' => $this->assigned_to,
            'cycle_count_plan_id' => $this->cycle_count_plan_id,
            'inventory_adjustment_id' => $this->inventory_adjustment_id,
            'approval_mode' => $this->approval_mode,
            'approval_level_required' => $this->approval_level_required,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'lines' => $this->whenLoaded('lines', fn (): array => $this->lines
                ->map(fn ($line): array => (new InventoryCountLineResource($line, ! $this->is_blind, $revealFirstCount))->resolve($request))
                ->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
