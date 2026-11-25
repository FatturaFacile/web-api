<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Budgeting\Models\BudgetSpendCheck;

/**
 * A logged spend control: a warning, a block or a block lifted by an override.
 *
 * @mixin BudgetSpendCheck
 */
final class SpendCheckResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'source_number' => $this->source_number,
            'stage' => $this->stage,
            'outcome' => $this->outcome,
            'rule_id' => $this->rule_id,
            'override_id' => $this->override_id,
            'account_id' => $this->account_id,
            'cost_center_id' => $this->cost_center_id,
            'project_id' => $this->project_id,
            'fiscal_year' => $this->fiscal_year,
            'budget_amount' => $this->budget_amount,
            'actual_amount' => $this->actual_amount,
            'committed_amount' => $this->committed_amount,
            'requested_amount' => $this->requested_amount,
            'available_amount' => $this->available_amount,
            'projected_percent' => $this->projected_percent,
            'user_id' => $this->user_id,
            'message' => $this->message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
