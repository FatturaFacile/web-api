<?php

declare(strict_types=1);

namespace App\Http\Resources\RecurringContracts;

use App\Models\RecurringContracts\PriceIndexRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PriceIndexRate
 */
class PriceIndexRateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'index_type' => $this->index_type,
            'period' => $this->period,
            'index_value' => (string) $this->index_value,
            'published_at' => $this->published_at?->toDateString(),
            'source_reference' => $this->source_reference,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
