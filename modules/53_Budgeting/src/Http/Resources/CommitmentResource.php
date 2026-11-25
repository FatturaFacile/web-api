<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Budgeting\Models\BudgetCommitment;

/**
 * A commitment of a requisition or purchase order against the budget.
 *
 * @mixin BudgetCommitment
 */
final class CommitmentResource extends JsonResource
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
            'parent_source_id' => $this->parent_source_id,
            'account_id' => $this->account_id,
            'cost_center_id' => $this->cost_center_id,
            'project_id' => $this->project_id,
            'fiscal_year' => $this->fiscal_year,
            'period' => $this->period,
            'commitment_date' => $this->commitment_date->toDateString(),
            'currency' => $this->currency,
            'amount' => $this->amount,
            'consumed_amount' => $this->consumed_amount,
            'released_amount' => $this->released_amount,
            'open_amount' => $this->openAmount(),
            'status' => $this->status,
            'release_reason' => $this->release_reason,
        ];
    }
}
