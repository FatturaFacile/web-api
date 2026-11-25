<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ExpenseManagement\Models\AciVehicleRate;

/**
 * @mixin AciVehicleRate
 */
final class AciRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'vehicle_code' => $this->vehicle_code,
            'make_model' => $this->make_model,
            'fuel' => $this->fuel,
            'vehicle_class' => $this->vehicle_class,
            'annual_km_bracket' => $this->annual_km_bracket,
            'rate_per_km' => $this->rate_per_km,
            'currency' => $this->currency,
            'rate_period' => $this->rate_period,
            'valid_from' => $this->valid_from->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
        ];
    }
}
