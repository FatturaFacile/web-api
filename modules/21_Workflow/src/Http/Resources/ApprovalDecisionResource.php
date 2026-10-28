<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Workflow\Models\ApprovalDecision;

/**
 * @mixin ApprovalDecision
 */
final class ApprovalDecisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_instance_step_id' => $this->workflow_instance_step_id,
            'user_id' => $this->user_id,
            'effective_user_id' => $this->effective_user_id,
            'decision' => $this->decision,
            'comment' => $this->comment,
            'decided_at' => $this->decided_at?->toIso8601String(),
        ];
    }
}
