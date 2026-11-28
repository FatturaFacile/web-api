<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\BondedWarehouses\Models\CustomsWarehouseAuthorization;

/**
 * @mixin CustomsWarehouseAuthorization
 */
final class AuthorizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'authorization_code' => $this->authorization_code,
            'customs_office_code' => $this->customs_office_code,
            'regime_type' => $this->regime_type->value,
            'warehouse_subtype' => $this->warehouse_subtype->value,
            'cgu_guarantee_reference' => $this->cgu_guarantee_reference,
            'cgu_max_ceiling_amount' => $this->cgu_max_ceiling_amount,
            'cgu_utilized_amount' => $this->cgu_utilized_amount,
            'cgu_available_amount' => $this->availablePlafond(),
            'cgu_utilization_percent' => $this->utilizationPercent(),
            'currency' => $this->currency,
            'aeo_certificate_number' => $this->aeo_certificate_number,
            'aeo_status' => $this->aeo_status->value,
            'aeo_next_audit_date' => $this->aeo_next_audit_date?->format('Y-m-d'),
            'valid_from' => $this->valid_from->format('Y-m-d'),
            'valid_to' => $this->valid_to?->format('Y-m-d'),
            'is_active' => $this->is_active,
        ];
    }
}
