<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgItem;

/**
 * @mixin MfgItem
 */
final class MfgItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_code' => $this->item_code,
            'name' => $this->name,
            'description' => $this->description,
            'item_type' => $this->item_type,
            'uom' => $this->uom,
            'standard_cost' => (float) $this->standard_cost,
            'current_cost' => (float) $this->current_cost,
            'safety_stock' => (float) $this->safety_stock,
            'min_lot_size' => (float) $this->min_lot_size,
            'lead_time_days' => $this->lead_time_days,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
