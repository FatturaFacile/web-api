<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Analytics\Models\ReportDefinition;

/**
 * API representation of a saved report definition.
 *
 * @mixin ReportDefinition
 */
final class ReportDefinitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'description' => $this->description,
            'kpis' => $this->kpis,
            'period_preset' => $this->period_preset,
            'from_date' => $this->from_date?->toDateString(),
            'to_date' => $this->to_date?->toDateString(),
            'compare' => $this->compare,
            'is_shared' => $this->is_shared,
            'is_owner' => (string) $this->user_id === (string) $request->user()?->id,
        ];
    }
}
