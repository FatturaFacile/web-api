<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WarehouseBinResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_rack_id' => $this->warehouse_rack_id,
            'code' => $this->code,
            'barcode' => $this->barcode,
            'bin_type' => $this->bin_type,
            'max_weight_kg' => (float) $this->max_weight_kg,
            'max_volume_cbm' => (float) $this->max_volume_cbm,
            'is_quarantine' => (bool) $this->is_quarantine,
            'is_active' => (bool) $this->is_active,
            'rack' => $this->whenLoaded('rack'),
            'quants' => $this->whenLoaded('quants'),
        ];
    }
}
