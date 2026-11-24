<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Listeners;

use Enterprise\EventBus\Models\TransactionalInbox;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Contracts\SubscriptionLifecycleServiceInterface;
use Modules\SubscriptionBilling\DTOs\CancelSubscriptionDTO;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;

/**
 * The approval workflow of a subscription waiting for approval: approved activates it, any other outcome cancels it
 * (without refund: nothing was billed while it waited). Processed once per workflow instance.
 */
final readonly class WorkflowApprovalSubscriptionListener
{
    public function __construct(private SubscriptionLifecycleServiceInterface $lifecycle) {}

    public function handle(WorkflowInstanceCompletedEvent $event): void
    {
        if ($event->aggregateType !== 'sub_subscription' || TransactionalInbox::where('message_id', $event->instanceId)->exists()) {
            return;
        }

        TransactionalInbox::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $event->tenantId,
            'message_id' => $event->instanceId,
            'event_type' => $event->getEventName(),
            'payload_json' => $event->toPayload(),
            'received_at' => now(),
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        $subscription = Subscription::withoutGlobalScopes()->where('tenant_id', $event->tenantId)->whereKey($event->aggregateId)->first();

        if ($subscription === null || ! in_array($subscription->status, ['pending', 'draft'], true)) {
            return;
        }

        $subscription->forceFill(['is_locked' => false])->save();

        if ($event->status === 'approved') {
            $this->lifecycle->activateSubscription((string) $subscription->id);

            return;
        }

        $this->lifecycle->cancelSubscription(new CancelSubscriptionDTO(
            subscriptionId: (string) $subscription->id,
            reason: "Approval workflow ended with status {$event->status}",
            refund: false,
        ));
    }
}
