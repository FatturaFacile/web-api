<?php

declare(strict_types=1);

namespace App\Http\Resources\Procurement\PurchaseRequisition;

use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseRequisition
 */
final class PurchaseRequisitionResource extends JsonResource
{
    /**
     * @param  list<array<string, mixed>>|null  $approvalSteps
     */
    public function __construct(
        mixed $resource,
        private readonly ?array $approvalSteps = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PurchaseRequisition $pr */
        $pr = $this->resource;

        return [
            'id' => (string) $pr->id,
            'tenant_id' => $pr->tenant_id !== null ? (string) $pr->tenant_id : null,
            'requisition_number' => (string) $pr->requisition_number,
            'title' => (string) $pr->title,
            'requester_user_id' => $pr->effectiveRequesterId(),
            'requester' => $this->whenLoaded('requester', fn (): ?array => $pr->requester !== null ? [
                'id' => (string) $pr->requester->id,
                'name' => (string) $pr->requester->name,
                'email' => (string) $pr->requester->email,
            ] : null),
            'department_id' => $pr->department_id,
            'cost_center_id' => $pr->cost_center_id,
            'project_id' => $pr->project_id,
            'project' => $this->whenLoaded('project', fn (): ?array => $pr->project !== null ? [
                'id' => (string) $pr->project->id,
                'project_number' => (string) $pr->project->project_number,
                'name' => (string) $pr->project->name,
            ] : null),
            'urgency' => (string) $pr->urgency,
            'requested_delivery_date' => ($pr->requested_delivery_date ?? $pr->required_by_date)?->toDateString(),
            'economic_justification' => $pr->economic_justification,
            'currency' => (string) $pr->currency,
            'total_estimated_amount' => (string) $pr->total_estimated_amount,
            'status' => (string) $pr->status,
            'budget_check_snapshot' => $pr->budget_check_snapshot,
            'budget_warning_acknowledged' => (bool) $pr->budget_warning_acknowledged,
            'current_approval_level' => $pr->current_approval_level,
            'is_locked' => (bool) $pr->is_locked,
            'notes' => $pr->notes,
            'workflow_instance_id' => $pr->workflow_instance_id,
            'submitted_at' => $pr->submitted_at?->toIso8601String(),
            'approved_at' => $pr->approved_at?->toIso8601String(),
            'rejected_at' => $pr->rejected_at?->toIso8601String(),
            'rejection_reason' => $pr->rejection_reason,
            'cancelled_at' => $pr->cancelled_at?->toIso8601String(),
            'converted_at' => $pr->converted_at?->toIso8601String(),
            'lines' => PurchaseRequisitionLineResource::collection($this->whenLoaded('lines')),
            'approval_logs' => PurchaseRequisitionApprovalLogResource::collection($this->whenLoaded('approvalLogs')),
            'document_links' => PurchaseRequisitionDocumentLinkResource::collection($this->whenLoaded('documentLinks')),
            'approval_steps' => $this->when($this->approvalSteps !== null, $this->approvalSteps),
            'created_at' => $pr->created_at?->toIso8601String(),
            'updated_at' => $pr->updated_at?->toIso8601String(),
        ];
    }
}
