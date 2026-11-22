<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\InventoryCountApproval;

/**
 * One approval level of the differences of a count: who may decide it, its status and the decision trail.
 *
 * @mixin InventoryCountApproval
 */
final class InventoryCountApprovalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inventory_adjustment_id' => $this->inventory_adjustment_id,
            'level' => (int) $this->level,
            'required_role' => $this->required_role,
            'status' => $this->status,
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
