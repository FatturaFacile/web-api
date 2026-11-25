<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\IndustrialLaundry\Models\RollCage;
use Modules\IndustrialLaundry\Support\ContactName;

/**
 * A roll cage with its tare weight, ready for the weighing station and the UHF gate middleware.
 *
 * @mixin RollCage
 */
final class RollCageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'barcode' => $this->barcode,
            'label' => $this->label,
            'tare_weight_kg' => (string) $this->tare_weight_kg,
            'max_gross_weight_kg' => $this->max_gross_weight_kg !== null ? (string) $this->max_gross_weight_kg : null,
            'is_active' => $this->is_active,
            'holder_contact_id' => $this->holder_contact_id,
            'holder' => $this->relationLoaded('holder') ? ContactName::summary($this->holder) : null,
            'last_scanned_at' => $this->last_scanned_at?->toIso8601String(),
        ];
    }
}
