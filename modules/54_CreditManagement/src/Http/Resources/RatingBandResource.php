<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CreditManagement\Models\CreditRatingBand;

/**
 * A rating band.
 *
 * @mixin CreditRatingBand
 */
final class RatingBandResource extends JsonResource
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
            'min_score' => $this->min_score,
            'max_score' => $this->max_score,
            'risk_level' => $this->risk_level,
            'limit_multiplier' => $this->limit_multiplier,
            'limit_ceiling' => $this->limit_ceiling,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
