<?php

declare(strict_types=1);

namespace App\Http\Resources\Cpq;

use App\Models\QuoteApprovalRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QuoteApprovalRequest
 */
class QuoteApprovalRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quote_id' => $this->quote_id,
            'level' => $this->level,
            'required_role' => $this->required_role,
            'status' => $this->status,
            'triggers' => $this->triggers ?? [],
            'requested_by' => $this->requested_by,
            'approved_by' => $this->approved_by,
            'rejected_by' => $this->rejected_by,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
        ];
    }
}
