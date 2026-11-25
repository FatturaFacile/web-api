<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DebtCollection\Models\InterestRate;

/**
 * @mixin InterestRate
 */
final class InterestRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'jurisdiction' => $this->jurisdiction,
            'kind' => $this->kind,
            'base_rate_percent' => (string) $this->base_rate_percent,
            'markup_percent' => (string) $this->markup_percent,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'is_global' => $this->tenant_id === null,
            'notes' => $this->notes,
        ];
    }
}
