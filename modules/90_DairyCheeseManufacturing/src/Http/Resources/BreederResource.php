<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DairyCheeseManufacturing\Models\Breeder;

/**
 * @mixin Breeder
 */
final class BreederResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'jurisdiction' => $this->jurisdiction, 'contact_id' => $this->contact_id,
            'farm_code' => $this->farm_code, 'tvd_number' => $this->tvd_number, 'bdm_number' => $this->bdm_number, 'asl_code' => $this->asl_code, 'cuaa' => $this->cuaa,
            'municipality' => $this->municipality, 'canton_or_province' => $this->canton_or_province, 'is_active' => (bool) $this->is_active, 'notes' => $this->notes,
        ];
    }
}
