<?php

declare(strict_types=1);

namespace Modules\Inventory\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\InventoryCountSession;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;
use Modules\Workflow\Models\WorkflowInstance;

final readonly class WorkflowApprovalInventoryListener implements ShouldQueue
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
            if ($adjustment) {
                $adjustment->update([
                    'status' => 'approved',
                    'is_locked' => false,
                    'approved_at' => now(),
                ]);
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
}
