<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Models\TransactionalInbox;
use Illuminate\Support\Str;
use Modules\Projects\Events\ProjectClosureReadyEvent;
use Modules\Projects\Events\ProjectCreatedEvent;
use Modules\ServiceManagement\Models\WorkOrder;

final readonly class ProjectESMListener
{
    public function __construct(
        private AuditLedgerInterface $auditLedger
    ) {}

    public function handleProjectCreated(ProjectCreatedEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'ProjectESMListener::handleProjectCreated')) {
            return;
        }

        // Project initialized - ESM is ready for project-linked field service work orders
    }

    public function handleProjectClosureReady(ProjectClosureReadyEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'ProjectESMListener::handleProjectClosureReady')) {
            return;
        }

        // Ensure all work orders linked to this project are closed or completed
        $openWorkOrders = WorkOrder::where('project_id', $event->projectId)
            ->whereIn('status', ['draft', 'scheduled', 'dispatched', 'in_progress'])
            ->get();

        foreach ($openWorkOrders as $wo) {
            $wo->update(['status' => 'cancelled']);
            $this->auditLedger->record(
                'ESM_WORK_ORDER_CANCELLED_ON_PROJECT_CLOSURE',
                $wo,
                null,
                ['project_id' => $event->projectId],
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
