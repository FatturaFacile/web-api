<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Models\TransactionalInbox;
use Illuminate\Support\Str;
use Modules\AssetManagement\Events\AssetMaintenanceCreatedEvent;
use Modules\AssetManagement\Events\MaintenanceCompletedEvent;
use Modules\ServiceManagement\Models\WorkOrder;

final readonly class AssetMaintenanceESMListener
{
    public function __construct(
        private AuditLedgerInterface $auditLedger
    ) {}

    public function handleAssetMaintenanceCreated(AssetMaintenanceCreatedEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'AssetMaintenanceESMListener::handleAssetMaintenanceCreated')) {
            return;
        }

        $woNumber = 'WO-EAM-'.substr($event->workOrderNumber, -8);

        $workOrder = WorkOrder::create([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'work_order_number' => $woNumber,
            'title' => 'EAM Maintenance: '.$event->workOrderNumber,
            'description' => 'Dispatched from Enterprise Asset Management',
            'work_type' => $event->maintenanceType,
            'priority' => $event->priority,
            'status' => 'draft',
            'customer_id' => (string) Str::uuid7(),
            'asset_id' => $event->assetId,
            'scheduled_start' => $event->scheduledDate,
            'total_cost' => $event->totalCost,
        ]);

        $this->auditLedger->record(
            'ESM_WORK_ORDER_CREATED_FROM_EAM',
            $workOrder,
            null,
            ['eam_work_order_id' => $event->workOrderId, 'asset_id' => $event->assetId],
            'MODULE_17_ESM'
        );
    }

    public function handleMaintenanceCompleted(MaintenanceCompletedEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'AssetMaintenanceESMListener::handleMaintenanceCompleted')) {
            return;
        }

        $woNumber = 'WO-EAM-'.substr($event->workOrderNumber, -8);
        $workOrder = WorkOrder::where('work_order_number', $woNumber)->first();

        if ($workOrder !== null) {
            $workOrder->update([
                'status' => 'completed',
                'actual_end' => $event->completedAt,
                'total_cost' => $event->totalCost,
            ]);

            $this->auditLedger->record(
                'ESM_WORK_ORDER_COMPLETED_FROM_EAM',
                $workOrder,
                null,
                ['eam_work_order_id' => $event->workOrderId, 'total_cost' => $event->totalCost],
                'MODULE_17_ESM'
            );
        }
    }

    private function recordInboxIfNew(DomainEventInterface $event, string $consumerName): bool
    {
        $messageId = $event->getEventId();
        if (TransactionalInbox::where('message_id', $messageId)->exists()) {
            return false;
        }

        TransactionalInbox::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $event->getTenantId() ?: $event->getCompanyId(),
            'message_id' => $messageId,
            'event_type' => $event->getEventName().':'.$consumerName,
            'payload_json' => $event->toPayload(),
            'status' => 'completed',
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        return true;
    }
}
