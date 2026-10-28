<?php

declare(strict_types=1);

namespace Modules\Procurement\Listeners;

use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Procurement\Events\PurchaseOrderApprovedEvent;
use Modules\Procurement\Events\PurchaseRequisitionApprovedEvent;
use Modules\Procurement\Events\ThreeWayMatchPassedEvent;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\ThreeWayMatchLog;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;
use Modules\Workflow\Models\WorkflowInstance;

final readonly class WorkflowApprovalProcurementListener implements ShouldQueue
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher
    ) {}

    public function handleCompleted(WorkflowInstanceCompletedEvent $event): void
    {
        $instance = WorkflowInstance::find($event->instanceId);
        if ($instance === null) {
            return;
        }

        if ($instance->subject_type === PurchaseOrder::class) {
            $order = PurchaseOrder::find($instance->subject_id);
            if ($order !== null) {
                $order->update([
                    'status' => 'approved',
                    'is_locked' => false,
                ]);

                $this->eventDispatcher->dispatch(new PurchaseOrderApprovedEvent(
                    purchaseOrderId: (string) $order->id,
                    poNumber: (string) ($order->po_number ?? $order->order_number),
                    approvedByUserId: (string) ($instance->assigned_to_user_id ?? $order->created_by ?? 'system'),
                    totalAmount: (float) $order->total_amount,
                    currency: (string) $order->currency,
                    tenantId: (string) $order->tenant_id,
                    companyId: (string) ($order->company_id ?? '')
                ));
            }
        } elseif ($instance->subject_type === PurchaseRequisition::class) {
            $requisition = PurchaseRequisition::find($instance->subject_id);
            if ($requisition !== null) {
                $requisition->update([
                    'status' => 'approved',
                    'is_locked' => false,
                ]);

                $this->eventDispatcher->dispatch(new PurchaseRequisitionApprovedEvent(
                    requisitionId: (string) $requisition->id,
                    requisitionNumber: (string) $requisition->requisition_number,
                    approvedByUserId: (string) ($instance->assigned_to_user_id ?? $requisition->requester_id ?? 'system'),
                    tenantId: (string) $requisition->tenant_id,
                    companyId: (string) ($requisition->company_id ?? '')
                ));
            }
        } elseif ($instance->subject_type === ThreeWayMatchLog::class) {
            $matchLog = ThreeWayMatchLog::find($instance->subject_id);
            if ($matchLog !== null) {
                $matchLog->update(['status' => 'matched']);

                $this->eventDispatcher->dispatch(new ThreeWayMatchPassedEvent(
                    matchLogId: (string) $matchLog->id,
                    purchaseOrderId: (string) $matchLog->purchase_order_id,
                    supplierId: (string) $matchLog->supplier_id,
                    matchedAmount: (float) $matchLog->invoice_amount,
                    tenantId: (string) $matchLog->tenant_id,
                    companyId: (string) ($matchLog->company_id ?? '')
                ));
            }
        }
    }

    public function handleRejected(WorkflowInstanceRejectedEvent $event): void
    {
        $instance = WorkflowInstance::find($event->instanceId);
        if ($instance === null) {
            return;
        }

        if ($instance->subject_type === PurchaseOrder::class) {
            $order = PurchaseOrder::find($instance->subject_id);
            if ($order !== null) {
                $order->update([
                    'status' => 'rejected',
                    'is_locked' => false,
                ]);
            }
        } elseif ($instance->subject_type === PurchaseRequisition::class) {
            $requisition = PurchaseRequisition::find($instance->subject_id);
            if ($requisition !== null) {
                $requisition->update([
                    'status' => 'rejected',
                    'is_locked' => false,
                ]);
            }
        } elseif ($instance->subject_type === ThreeWayMatchLog::class) {
            $matchLog = ThreeWayMatchLog::find($instance->subject_id);
            if ($matchLog !== null) {
                $matchLog->update(['status' => 'rejected']);
            }
        }
    }
}
