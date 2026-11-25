<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Budgeting\Models\BudgetOverride;

/**
 * An override (deroga) request with its decision.
 *
 * @mixin BudgetOverride
 */
final class OverrideResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'source_number' => $this->source_number,
            'account_id' => $this->account_id,
            'cost_center_id' => $this->cost_center_id,
            'project_id' => $this->project_id,
            'fiscal_year' => $this->fiscal_year,
            'requested_amount' => $this->requested_amount,
            'shortfall_amount' => $this->shortfall_amount,
            'approved_amount' => $this->approved_amount,
            'consumed_amount' => $this->consumed_amount,
            'remaining_amount' => $this->status === BudgetOverride::Approved ? $this->remaining() : '0.00',
            'reason' => $this->reason,
            'status' => $this->status,
            'is_expired' => $this->status === BudgetOverride::Approved && $this->isExpired(),
            'requested_by' => $this->requested_by,
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_comment' => $this->decision_comment,
            'valid_until' => $this->valid_until?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
