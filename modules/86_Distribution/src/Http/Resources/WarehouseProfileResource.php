<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Distribution\Models\WarehouseProfile;

/**
 * @mixin WarehouseProfile
 */
final class WarehouseProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'warehouse_id' => $this->warehouse_id,
            'role' => $this->role->value,
            'parent_warehouse_id' => $this->parent_warehouse_id,
            'default_transit_warehouse_id' => $this->default_transit_warehouse_id,
            'third_party_partner_id' => $this->third_party_partner_id,
            'external_reference' => $this->external_reference,
            'settlement_trigger' => $this->settlement_trigger?->value,
            'billing_frequency' => $this->billing_frequency?->value,
            'max_consignment_days' => $this->max_consignment_days,
            'is_insured' => $this->is_insured,
            'counts_in_valuation' => $this->counts_in_valuation,
            'include_in_free_stock' => $this->include_in_free_stock,
            'is_blocked' => $this->is_blocked,
            'is_counting' => $this->is_counting,
            'notes' => $this->notes,
        ];
    }
}
