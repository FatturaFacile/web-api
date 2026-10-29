<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Listeners;

use Enterprise\EventBus\Models\TransactionalInbox;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;

final readonly class WorkflowApprovalSubscriptionListener
{
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

        if ($event->aggregateType === 'sub_subscription') {
            Subscription::where('id', $event->aggregateId)
                ->where('tenant_id', $event->tenantId)
                ->update([
                    'is_locked' => false,
                    'status' => 'active',
                ]);
        }
    }
}
