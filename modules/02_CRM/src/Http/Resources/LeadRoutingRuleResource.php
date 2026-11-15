<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Models\LeadRoutingRule;

/**
 * @mixin LeadRoutingRule
 */
final class LeadRoutingRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $conditions = is_array($this->conditions) ? $this->conditions : [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'priority' => $this->priority,
            'conditions' => [
                'countries' => $conditions['countries'] ?? [],
                'regions' => $conditions['regions'] ?? [],
                'product_lines' => $conditions['product_lines'] ?? [],
                'employee_min' => $conditions['employee_min'] ?? null,
                'employee_max' => $conditions['employee_max'] ?? null,
                'ratings' => $conditions['ratings'] ?? [],
            ],
            'strategy' => $this->strategy,
            'assignee_user_ids' => $this->assigneeIds(),
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
