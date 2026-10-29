<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Listeners;

use Enterprise\EventBus\Models\TransactionalInbox;
use Illuminate\Support\Str;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;

final readonly class WorkflowApprovalManufacturingListener
{
    public function __construct(
        private BillOfMaterialsServiceInterface $bomService
    ) {}

    public function handle(WorkflowInstanceCompletedEvent $event): void
    {
        $messageId = $event->instanceId;
        if (TransactionalInbox::where('message_id', $messageId)->exists()) {
            return;
        }

        TransactionalInbox::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $event->tenantId,
            'message_id' => $messageId,
            'event_type' => $event->getEventName(),
            'payload_json' => $event->toPayload(),
            'received_at' => now(),
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        if ($event->aggregateType === 'mfg_production_order') {
            MfgProductionOrder::where('id', $event->aggregateId)
                ->where('tenant_id', $event->tenantId)
                ->update([
                    'is_locked' => false,
                    'status' => 'released',
                ]);
        } elseif ($event->aggregateType === 'mfg_bom') {
            MfgBom::where('id', $event->aggregateId)
                ->where('tenant_id', $event->tenantId)
                ->update([
                    'is_locked' => false,
                    'status' => 'active',
                ]);
        } elseif ($event->aggregateType === 'mfg_engineering_change_order') {
            $this->bomService->approveEco($event->aggregateId, (string) Str::uuid7());
        }
    }
}
