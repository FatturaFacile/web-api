<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectRateCard;

/**
 * API representation of a rate card (schema ProjectsRateCard).
 *
 * @mixin ProjectRateCard
 */
final class ProjectRateCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectRateCard $card */
        $card = $this->resource;

        return [
            'id' => $card->id,
            'project_id' => $card->project_id,
            'user_id' => $card->user_id,
            'role' => $card->role,
            'cost_rate' => (string) $card->cost_rate,
            'bill_rate' => (string) $card->bill_rate,
            'valid_from' => $card->valid_from?->toDateString(),
            'valid_to' => $card->valid_to?->toDateString(),
        ];
    }
}
