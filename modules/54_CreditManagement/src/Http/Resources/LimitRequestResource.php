<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CreditManagement\Models\CreditLimitRequest;

/**
 * A credit limit request.
 *
 * @mixin CreditLimitRequest
 */
final class LimitRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'current_limit' => $this->current_limit,
            'requested_limit' => $this->requested_limit,
            'proposed_limit' => $this->proposed_limit,
            'approved_limit' => $this->approved_limit,
            'currency' => $this->currency,
            'status' => $this->status,
            'score_id' => $this->score_id,
            'reason' => $this->reason,
            'requested_by' => $this->requested_by,
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            'valid_until' => $this->valid_until?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'customer_name' => $this->customer_name ?? null,
        ];
    }
}
