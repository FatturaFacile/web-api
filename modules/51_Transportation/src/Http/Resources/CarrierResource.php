<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Transportation\Models\TmsCarrier;

/**
 * A freight carrier with the tariff tables used to rate shipments.
 *
 * @mixin TmsCarrier
 */
final class CarrierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'mode' => $this->mode,
            'service_level' => $this->service_level,
            'currency' => $this->currency,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
