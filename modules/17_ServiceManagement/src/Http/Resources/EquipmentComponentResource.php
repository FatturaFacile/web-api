<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\EquipmentComponent;

/**
 * @mixin EquipmentComponent
 */
final class EquipmentComponentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'component_serial' => $this->component_serial,
            'component_product_id' => $this->component_product_id,
            'role' => $this->role,
            'source' => $this->source,
            'installed_at' => $this->installed_at?->toIso8601String(),
            'removed_at' => $this->removed_at?->toIso8601String(),
            'replaced_by_component_id' => $this->replaced_by_component_id,
            'rma_order_id' => $this->rma_order_id,
        ];
    }
}
