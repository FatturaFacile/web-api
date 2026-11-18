<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\Warehouse;

/**
 * @mixin Warehouse
 */
final class WarehouseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->warehouse_type,
            'picking_strategy' => $this->picking_strategy,
            'is_virtual' => (bool) $this->is_virtual,
            'owner_contact_id' => $this->owner_contact_id,
            'address' => $this->address,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'is_active' => (bool) $this->is_active,
            'manager_user_id' => $this->manager_user_id,
            'is_locked' => (bool) $this->is_locked,
            'warehouse_type' => $this->warehouse_type,
            'legal_owner_type' => $this->legal_owner_type,
            'linked_partner_id' => $this->linked_partner_id,
            'company_entity_id' => $this->company_entity_id,
            'zones' => $this->whenLoaded('zones'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
