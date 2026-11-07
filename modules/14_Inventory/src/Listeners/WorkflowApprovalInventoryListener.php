<?php

declare(strict_types=1);

namespace Modules\Inventory\Listeners;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Modules\Inventory\Actions\PostInventoryAdjustmentAction;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\InventoryCountSession;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;
use Modules\Workflow\Models\ApprovalDecision;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;

final readonly class WorkflowApprovalInventoryListener implements ShouldQueueAfterCommit
{
    public function handleCompleted(WorkflowInstanceCompletedEvent $event): void
    {
        $instance = WorkflowInstance::find($event->instanceId);
        if (! $instance) {
            return;
        }

        $targetType = $instance->approvable_type ?? $instance->subject_type ?? null;
        $targetId = $instance->approvable_id ?? $instance->subject_id ?? null;

        if ($targetType === InventoryAdjustment::class) {
            $adjustment = InventoryAdjustment::find($targetId);
            if ($adjustment && $adjustment->status === 'pending_approval') {
                // Moves the stock of the lines sent for approval and posts the cost impact to the ledger.
                app(PostInventoryAdjustmentAction::class)->execute($adjustment, [], $this->approverOf($instance) ?? (string) $adjustment->created_by);
            }
        } elseif ($targetType === InventoryCountSession::class) {
            $session = InventoryCountSession::find($targetId);
            if ($session) {
                $session->update([
                    'status' => 'completed',
                    'is_locked' => false,
                    'completed_at' => now(),
                ]);
            }
        }
    }

    public function handleRejected(WorkflowInstanceRejectedEvent $event): void
    {
        $instance = WorkflowInstance::find($event->instanceId);
        if (! $instance) {
            return;
        }

        $targetType = $instance->approvable_type ?? $instance->subject_type ?? null;
        $targetId = $instance->approvable_id ?? $instance->subject_id ?? null;

        if ($targetType === InventoryAdjustment::class) {
            $adjustment = InventoryAdjustment::find($targetId);
            if ($adjustment) {
                $adjustment->update([
                    'status' => 'rejected',
                    'is_locked' => false,
                ]);
            }
        } elseif ($targetType === InventoryCountSession::class) {
            $session = InventoryCountSession::find($targetId);
            if ($session) {
                $session->update([
                    'status' => 'cancelled',
                    'is_locked' => false,
                ]);
            }
        }
    }

    /**
     * The user who took the last approval decision on the instance.
     */
    private function approverOf(WorkflowInstance $instance): ?string
    {
        $stepIds = WorkflowInstanceStep::withoutGlobalScopes()->where('workflow_instance_id', $instance->id)->pluck('id');
        $userId = ApprovalDecision::withoutGlobalScopes()->whereIn('workflow_instance_step_id', $stepIds)->latest('decided_at')->value('effective_user_id');

        return $userId !== null ? (string) $userId : null;
    }
}
