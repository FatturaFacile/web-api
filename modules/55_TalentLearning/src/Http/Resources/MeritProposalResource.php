<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TalentLearning\Models\CompProposal;

/**
 * A merit proposal.
 *
 * @mixin CompProposal
 */
final class MeritProposalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cycle_id' => $this->cycle_id,
            'budget_pool_id' => $this->budget_pool_id,
            'pool' => $this->whenLoaded('pool', fn (): array => ['id' => $this->pool->id, 'name' => $this->pool->name]),
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee'),
            'performance_rating' => $this->performance_rating,
            'current_salary' => $this->current_salary,
            'increase_percent' => $this->increase_percent,
            'increase_amount' => $this->increase_amount,
            'new_salary' => $this->new_salary,
            'is_exception' => $this->is_exception,
            'justification' => $this->justification,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'decided_by_user_id' => $this->decided_by_user_id,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
