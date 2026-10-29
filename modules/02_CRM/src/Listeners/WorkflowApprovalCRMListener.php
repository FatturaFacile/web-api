<?php

declare(strict_types=1);

namespace Modules\CRM\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Modules\CRM\Models\Forecast;
use Modules\CRM\Models\Opportunity;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

final readonly class WorkflowApprovalCRMListener
{
    public function __construct(
        private AuditLedgerInterface $auditLedger
    ) {}

    public function handleWorkflowApproved(WorkflowInstanceCompletedEvent $event): void
    {
        $instanceId = $event->instanceId;

        // 1. Check Opportunity
        $opp = Opportunity::where('workflow_instance_id', $instanceId)->first();
        if ($opp !== null && $opp->approval_status !== 'approved') {
            $opp->update(['approval_status' => 'approved']);

            $this->auditLedger->record(
                action: 'OPPORTUNITY_WORKFLOW_APPROVED',
                model: $opp,
                payloadBefore: ['approval_status' => 'pending_approval'],
                payloadAfter: ['approval_status' => 'approved'],
                moduleCode: 'MODULE_02_CRM'
            );

            return;
        }

        // 2. Check Forecast
        $forecast = Forecast::where('workflow_instance_id', $instanceId)->first();
        if ($forecast !== null && $forecast->status !== 'approved') {
            $forecast->update(['status' => 'approved']);

            $this->auditLedger->record(
                action: 'FORECAST_WORKFLOW_APPROVED',
                model: $forecast,
                payloadBefore: ['status' => 'submitted'],
                payloadAfter: ['status' => 'approved'],
                moduleCode: 'MODULE_02_CRM'
            );
        }
    }

    public function handleWorkflowRejected(WorkflowInstanceRejectedEvent $event): void
    {
        $instanceId = $event->instanceId;

        $opp = Opportunity::where('workflow_instance_id', $instanceId)->first();
        if ($opp !== null && $opp->approval_status !== 'rejected') {
            $opp->update(['approval_status' => 'rejected']);

            $this->auditLedger->record(
                action: 'OPPORTUNITY_WORKFLOW_REJECTED',
                model: $opp,
                payloadBefore: ['approval_status' => 'pending_approval'],
                payloadAfter: ['approval_status' => 'rejected'],
                moduleCode: 'MODULE_02_CRM'
            );

            return;
        }

        $forecast = Forecast::where('workflow_instance_id', $instanceId)->first();
        if ($forecast !== null && $forecast->status !== 'draft') {
            $forecast->update(['status' => 'draft']);

            $this->auditLedger->record(
                action: 'FORECAST_WORKFLOW_REJECTED',
                model: $forecast,
                payloadBefore: null,
                payloadAfter: ['status' => 'draft'],
                moduleCode: 'MODULE_02_CRM'
            );
        }
    }
}
