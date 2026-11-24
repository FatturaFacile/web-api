<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Returns\Models\Warranty;
use Modules\Returns\Services\WarrantyService;

/**
 * A warranty of the registry with its validity today.
 *
 * @mixin Warranty
 */
final class WarrantyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product?->name,
            'product_sku' => $this->product?->sku,
            'contact_id' => $this->contact_id,
            'customer_name' => $this->contact?->company_name ?: trim((string) $this->contact?->first_name.' '.(string) $this->contact?->last_name),
            'invoice_id' => $this->invoice_id,
            'serial_number' => $this->serial_number,
            'lot_number' => $this->lot_number,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'coverage_type' => $this->coverage_type,
            'coverage_terms' => $this->coverage_terms,
            'status' => $this->status,
            'void_reason' => $this->void_reason,
            'validity' => app(WarrantyService::class)->validity($this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
