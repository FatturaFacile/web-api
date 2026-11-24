<?php

declare(strict_types=1);

namespace App\Http\Resources\Procurement\PurchaseRequisition;

use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisitionApprovalLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseRequisitionApprovalLog
 */
final class PurchaseRequisitionApprovalLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PurchaseRequisitionApprovalLog $log */
        $log = $this->resource;

        return [
            'id' => (string) $log->id,
            'purchase_requisition_id' => (string) $log->purchase_requisition_id,
            'approval_step_level' => (int) $log->approval_step_level,
            'approver_user_id' => $log->approver_user_id,
            'approver' => $this->whenLoaded('approver', fn (): ?array => $log->approver !== null ? [
                'id' => (string) $log->approver->id,
                'name' => (string) $log->approver->name,
                'email' => (string) $log->approver->email,
            ] : null),
            'action' => (string) $log->action,
            'channel' => (string) $log->channel,
            'comment' => $log->comment,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'metadata' => $log->metadata,
            'acted_at' => $log->acted_at?->toIso8601String(),
        ];
    }
}
