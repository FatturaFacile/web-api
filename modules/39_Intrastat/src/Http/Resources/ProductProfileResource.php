<?php

declare(strict_types=1);

namespace Modules\Intrastat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Intrastat\Models\IntrastatProductProfile;

/**
 * @mixin IntrastatProductProfile
 */
final class ProductProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn (): ?string => $this->product?->name),
            'supply_kind' => $this->supply_kind->value,
            'cn8_code' => $this->cn8_code,
            'net_mass_kg' => $this->net_mass_kg,
            'supplementary_units_per_unit' => $this->supplementary_units_per_unit,
            'service_code' => $this->service_code,
            'origin_country_code' => $this->origin_country_code,
            'origin_province_code' => $this->origin_province_code,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
