<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Planning\Models\PlanningPublication;

/**
 * A published version of an approved plan with its frozen snapshot.
 *
 * @mixin PlanningPublication
 */
final class PublicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scenario_id' => $this->scenario_id,
            'version' => $this->version,
            'is_current' => $this->is_current,
            'snapshot' => $this->snapshot,
            'published_by' => $this->published_by,
            'published_at' => $this->published_at->toIso8601String(),
        ];
    }
}
