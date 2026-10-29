<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Modules\AssetManagement\Models\AssetDisposal;
use Modules\AssetManagement\Models\AssetTransfer;
use Modules\AssetManagement\Models\AssetValuation;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

final readonly class WorkflowApprovalAssetListener
{
    public function __construct(
        private AuditLedgerInterface $auditLedger
    ) {}

    public function handleWorkflowApproved(WorkflowInstanceCompletedEvent $event): void
    {
        $instanceId = $event->instanceId;

        $asset = FixedAsset::where('workflow_instance_id', $instanceId)->first();
        if ($asset !== null) {
            $asset->update([
                'approval_status' => 'approved',
                'is_locked' => false,
            ]);
            $this->auditLedger->record('ASSET_WORKFLOW_APPROVED', $asset, null, $asset->toArray(), 'MODULE_16_EAM');

            return;
        }

        $transfer = AssetTransfer::where('workflow_instance_id', $instanceId)->first();
        if ($transfer !== null) {
            $transfer->update([
                'status' => 'completed',
                'is_locked' => false,
                'completed_at' => now(),
            ]);
            $this->auditLedger->record('ASSET_TRANSFER_WORKFLOW_APPROVED', $transfer, null, $transfer->toArray(), 'MODULE_16_EAM');

            return;
        }

        $valuation = AssetValuation::where('workflow_instance_id', $instanceId)->first();
        if ($valuation !== null) {
            $valuation->update([
                'status' => 'approved',
                'is_locked' => false,
            ]);
            $this->auditLedger->record('ASSET_VALUATION_WORKFLOW_APPROVED', $valuation, null, $valuation->toArray(), 'MODULE_16_EAM');

            return;
        }

        $disposal = AssetDisposal::where('workflow_instance_id', $instanceId)->first();
        if ($disposal !== null) {
            $disposal->update([
                'status' => 'completed',
                'is_locked' => false,
                'completed_at' => now(),
            ]);
            $this->auditLedger->record('ASSET_DISPOSAL_WORKFLOW_APPROVED', $disposal, null, $disposal->toArray(), 'MODULE_16_EAM');

            return;
        }

        $wo = MaintenanceWorkOrder::where('workflow_instance_id', $instanceId)->first();
        if ($wo !== null) {
            $wo->update([
                'status' => 'scheduled',
                'is_locked' => false,
            ]);
            $this->auditLedger->record('MAINTENANCE_WORK_ORDER_WORKFLOW_APPROVED', $wo, null, $wo->toArray(), 'MODULE_16_EAM');
        }
    }

    public function handleWorkflowRejected(WorkflowInstanceRejectedEvent $event): void
    {
        $instanceId = $event->instanceId;

        $asset = FixedAsset::where('workflow_instance_id', $instanceId)->first();
        if ($asset !== null) {
            $asset->update([
                'status' => 'registered',
                'approval_status' => 'rejected',
                'is_locked' => false,
            ]);
            $this->auditLedger->record('ASSET_WORKFLOW_REJECTED', $asset, null, $asset->toArray(), 'MODULE_16_EAM');

            return;
        }

        $transfer = AssetTransfer::where('workflow_instance_id', $instanceId)->first();
        if ($transfer !== null) {
            $transfer->update([
                'status' => 'rejected',
                'is_locked' => false,
            ]);
            $this->auditLedger->record('ASSET_TRANSFER_WORKFLOW_REJECTED', $transfer, null, $transfer->toArray(), 'MODULE_16_EAM');

            return;
        }

        $valuation = AssetValuation::where('workflow_instance_id', $instanceId)->first();
        if ($valuation !== null) {
            $valuation->update([
                'status' => 'rejected',
                'is_locked' => false,
            ]);
            $this->auditLedger->record('ASSET_VALUATION_WORKFLOW_REJECTED', $valuation, null, $valuation->toArray(), 'MODULE_16_EAM');

            return;
        }

        $disposal = AssetDisposal::where('workflow_instance_id', $instanceId)->first();
        if ($disposal !== null) {
            $disposal->update([
                'status' => 'rejected',
                'is_locked' => false,
            ]);
            $this->auditLedger->record('ASSET_DISPOSAL_WORKFLOW_REJECTED', $disposal, null, $disposal->toArray(), 'MODULE_16_EAM');

            return;
        }

        $wo = MaintenanceWorkOrder::where('workflow_instance_id', $instanceId)->first();
        if ($wo !== null) {
            $wo->update([
                'status' => 'cancelled',
                'is_locked' => false,
            ]);
            $this->auditLedger->record('MAINTENANCE_WORK_ORDER_WORKFLOW_REJECTED', $wo, null, $wo->toArray(), 'MODULE_16_EAM');
        }
    }
}
