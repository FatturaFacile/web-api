<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgMrpShortage;

/**
 * The netting of an item that MRP found short. Quantities are decimal strings.
 *
 * @mixin MfgMrpShortage
 */
final class MfgShortageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mrp_run_id' => $this->mrp_run_id,
            'mfg_item_id' => $this->mfg_item_id,
            'item_code' => $this->whenLoaded('mfgItem', fn () => $this->mfgItem?->item_code),
            'item_name' => $this->whenLoaded('mfgItem', fn () => $this->mfgItem?->name),
            'required_date' => $this->required_date->toDateString(),
            'gross_requirement' => $this->gross_requirement,
            'available_inventory' => $this->available_inventory,
            'scheduled_receipts' => $this->scheduled_receipts,
            'safety_stock' => $this->safety_stock,
            'net_shortage' => $this->net_shortage,
            'recommended_action' => $this->recommended_action,
        ];
    }
}
