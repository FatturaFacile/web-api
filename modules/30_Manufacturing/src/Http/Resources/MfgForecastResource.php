<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgDemandForecast;

/**
 * A demand forecast entry.
 *
 * @mixin MfgDemandForecast
 */
final class MfgForecastResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mfg_item_id' => $this->mfg_item_id,
            'forecast_date' => $this->forecast_date->toDateString(),
            'quantity' => $this->quantity,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
