<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;

final class InvoiceApprovalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $instance = $this->approval_workflow_instance_id === null ? null : WorkflowInstance::query()->with('steps')->find($this->approval_workflow_instance_id);

        return [
            'invoice_id' => (string) $this->id,
            'approval_status' => $this->approval_status,
            'is_payment_blocked' => $this->isPaymentBlocked(),
            'cost_center_id' => $this->cost_center_id,
            'workflow_instance_id' => $this->approval_workflow_instance_id,
            'decided_at' => $this->approval_decided_at?->toIso8601String(),
            'steps' => $instance?->steps->sortBy('tier_level')->map(static fn (WorkflowInstanceStep $step): array => [
                'id' => (string) $step->id,
                'tier_level' => $step->tier_level,
                'assigned_role' => $step->assigned_role,
                'assigned_user_id' => $step->assigned_user_id,
                'status' => $step->status,
            ])->values()->all() ?? [],
        ];
    }
}
