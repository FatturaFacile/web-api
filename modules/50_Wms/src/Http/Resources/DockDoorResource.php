<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Wms\Models\WmsDockDoor;

/**
 * A dock door of a warehouse where carriers load or unload. Appointments are booked per door.
 *
 * @mixin WmsDockDoor
 */
final class DockDoorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'code' => $this->code,
            'name' => $this->name,
            'direction' => $this->direction,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'warehouse' => $this->whenLoaded('warehouse', fn (): ?array => $this->warehouse !== null ? ['id' => $this->warehouse->id, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
