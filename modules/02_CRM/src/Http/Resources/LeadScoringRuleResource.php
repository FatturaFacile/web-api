<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Models\LeadScoringRule;

/**
 * @mixin LeadScoringRule
 */
final class LeadScoringRuleResource extends JsonResource
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
            'category' => $this->category,
            'field' => $this->field,
            'event_type' => $this->event_type,
            'operator' => $this->operator,
            'value' => $this->value,
            'points' => $this->points,
            'max_applications' => $this->max_applications,
            'decay_days' => $this->decay_days,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
