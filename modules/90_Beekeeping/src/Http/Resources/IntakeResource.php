<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Beekeeping\Models\SuperIntake;

/**
 * @mixin SuperIntake
 */
final class IntakeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'apiary_id' => $this->apiary_id,
            'extraction_batch_id' => $this->extraction_batch_id,
            'harvested_on' => $this->harvested_on->toDateString(),
            'botanical_origin' => $this->botanical_origin,
            'supers_count' => $this->supers_count,
            'gross_weight_kg' => $this->gross_weight_kg,
            'net_weight_kg' => $this->net_weight_kg,
            'notes' => $this->notes,
        ];
    }
}
