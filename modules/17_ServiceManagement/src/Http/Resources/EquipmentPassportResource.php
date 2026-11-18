<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Services\ContactNameResolver;

/**
 * @mixin SerializedEquipmentPassport
 */
final class EquipmentPassportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $customer = $this->relationLoaded('customer') ? $this->customer : null;

        return [
            'id' => $this->id,
            'serial_number' => $this->serial_number,
            'stock_serial_number_id' => $this->stock_serial_number_id,
            'product_id' => $this->product_id,
            'current_owner_customer_id' => $this->current_owner_customer_id,
            'customer' => $customer !== null ? [
                'id' => $customer->id,
                'name' => ContactNameResolver::nameOf($customer),
                'country' => $customer->country,
            ] : null,
            'original_sales_invoice_id' => $this->original_sales_invoice_id,
            'customer_type' => $this->customer_type,
            'jurisdiction' => $this->jurisdiction,
            'delivery_date' => $this->delivery_date->toDateString(),
            'warranty_policy_type' => $this->warranty_policy_type,
            'warranty_start_date' => $this->warranty_start_date?->toDateString(),
            'warranty_expiry_date' => $this->warranty_expiry_date->toDateString(),
            'warranty_source' => $this->warranty_source,
            'contract_warranty_months' => $this->contract_warranty_months,
            'contract_clause_reference' => $this->contract_clause_reference,
            'installed_firmware_version' => $this->installed_firmware_version,
            'extensions' => WarrantyExtensionResource::collection($this->whenLoaded('extensions', fn () => $this->extensions, collect())),
            'components' => EquipmentComponentResource::collection($this->whenLoaded('components', fn () => $this->components, collect())),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
