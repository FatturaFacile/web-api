<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Support;

use App\Domain\Procurement\PurchaseRequisition\Enums\ApprovalChannel;
use App\Domain\Procurement\PurchaseRequisition\Enums\ApprovalLogAction;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisitionApprovalLog;
use Carbon\CarbonImmutable;

/**
 * Shared writer for append-only, immutable Mod 044 Purchase Requisition approval & budget audit logs.
 */
final readonly class RequisitionApprovalLogWriter
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function append(
        PurchaseRequisition $requisition,
        int $stepLevel,
        ?string $actorUserId,
        ApprovalLogAction $action,
        ApprovalChannel $channel,
        ?string $comment = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?array $metadata = null,
    ): PurchaseRequisitionApprovalLog {
        $now = CarbonImmutable::now('UTC');

        return PurchaseRequisitionApprovalLog::query()->create([
            'tenant_id' => (string) $requisition->tenant_id,
            'purchase_requisition_id' => $requisition->id,
            'approval_step_level' => $stepLevel,
            'approver_user_id' => $actorUserId,
            'action' => $action->value,
            'channel' => $channel->value,
            'comment' => $comment,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'metadata' => $metadata,
            'acted_at' => $now,
            'created_at' => $now,
        ]);
    }
}
