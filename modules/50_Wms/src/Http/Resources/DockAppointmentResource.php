<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Wms\Models\WmsDockAppointment;

/**
 * A carrier slot on a dock door. Two active appointments never overlap on the same door.
 *
 * @mixin WmsDockAppointment
 */
final class DockAppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dock_door_id' => $this->dock_door_id,
            'warehouse_id' => $this->warehouse_id,
            'carrier_name' => $this->carrier_name,
            'reference' => $this->reference,
            'direction' => $this->direction,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'status' => $this->status,
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cross_dock_order_id' => $this->cross_dock_order_id,
            'notes' => $this->notes,
            'door' => $this->whenLoaded('door', fn (): ?array => $this->door !== null ? ['id' => $this->door->id, 'code' => $this->door->code, 'name' => $this->door->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
