<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TravelExpense\Models\TravelMileageRate;

/**
 * A row of the mileage rate table.
 *
 * @mixin TravelMileageRate
 */
final class MileageRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'jurisdiction' => $this->jurisdiction,
            'year' => $this->year,
            'vehicle_type' => $this->vehicle_type,
            'currency' => $this->currency,
            'rate_per_km' => $this->rate_per_km,
            'notes' => $this->notes,
        ];
    }
}
