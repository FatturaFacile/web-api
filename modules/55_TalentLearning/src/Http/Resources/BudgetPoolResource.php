<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TalentLearning\Models\CompBudgetPool;

/**
 * A budget pool of a cycle.
 *
 * @mixin CompBudgetPool
 */
final class BudgetPoolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cycle_id' => $this->cycle_id,
            'name' => $this->name,
            'department_id' => $this->department_id,
            'budget_amount' => $this->budget_amount,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
