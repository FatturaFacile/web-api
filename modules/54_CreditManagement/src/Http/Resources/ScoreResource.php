<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CreditManagement\Models\CreditScore;

/**
 * A score of a customer with the breakdown per factor.
 *
 * @mixin CreditScore
 */
final class ScoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'score' => $this->score,
            'rating_band_id' => $this->rating_band_id,
            'rating' => $this->rating,
            'risk_level' => $this->risk_level,
            'breakdown' => $this->breakdown,
            'scored_on' => $this->scored_on?->toDateString(),
            'scored_by' => $this->scored_by,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'customer_name' => $this->customer_name ?? null,
        ];
    }
}
