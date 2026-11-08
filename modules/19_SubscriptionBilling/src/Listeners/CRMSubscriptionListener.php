<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Listeners;

use App\Models\Contact;
use Enterprise\EventBus\Models\TransactionalInbox;
use Illuminate\Support\Str;
use Modules\CRM\Events\OpportunityWonEvent;
use Modules\SubscriptionBilling\Contracts\SubscriptionLifecycleServiceInterface;
use Modules\SubscriptionBilling\DTOs\CreateSubscriptionDTO;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;

/**
 * A won opportunity provisions a subscription only when the company opted in: the plan to use is the one active plan
 * flagged `metadata.auto_provision_on_opportunity_won`, and the customer must be a contact of the company. Nothing is
 * guessed otherwise. Processed once per event.
 */
final readonly class CRMSubscriptionListener
{
    public function __construct(private SubscriptionLifecycleServiceInterface $lifecycle) {}

    public function handle(OpportunityWonEvent $event): void
    {
        $messageId = $event->getEventId();

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

        $plans = SubscriptionPlan::withoutGlobalScopes()
            ->where('tenant_id', $event->tenantId)
            ->where('is_active', true)
            ->where('metadata->auto_provision_on_opportunity_won', true)
            ->get();

        $customerId = $event->contactId ?: $event->accountId;

        if ($plans->count() !== 1 || ! Contact::withoutGlobalScopes()->where('tenant_id', $event->tenantId)->whereKey($customerId)->exists()) {
            return;
        }

        $this->lifecycle->createSubscription(new CreateSubscriptionDTO(
            tenantId: $event->tenantId,
            companyId: $event->companyId,
            customerId: (string) $customerId,
            planId: (string) $plans->first()->id,
            startDate: now()->toDateString(),
            metadata: ['origin' => 'crm_opportunity', 'opportunity_id' => $event->opportunityId, 'won_amount' => number_format($event->amount, 2, '.', '')],
        ), Str::isUuid($event->actorUserId) ? $event->actorUserId : null);
    }
}
