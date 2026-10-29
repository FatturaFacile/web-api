<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Listeners;

use Enterprise\EventBus\Models\TransactionalInbox;
use Illuminate\Support\Str;
use Modules\CRM\Events\OpportunityWonEvent;
use Modules\SubscriptionBilling\Contracts\SubscriptionLifecycleServiceInterface;
use Modules\SubscriptionBilling\DTOs\CreateSubscriptionDTO;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;

final readonly class CRMSubscriptionListener
{
    public function __construct(
        private SubscriptionLifecycleServiceInterface $lifecycleService
    ) {}

    public function handle(OpportunityWonEvent $event): void
    {
        $inboxId = (string) Str::uuid7();
        $messageId = $event->getEventId();

        $alreadyProcessed = TransactionalInbox::where('message_id', $messageId)->exists();
        if ($alreadyProcessed) {
            return;
        }

        TransactionalInbox::create([
            'id' => $inboxId,
            'tenant_id' => $event->tenantId,
            'message_id' => $messageId,
            'event_type' => $event->getEventName(),
            'payload_json' => $event->toPayload(),
            'received_at' => now(),
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        $plan = SubscriptionPlan::where('tenant_id', $event->tenantId)
            ->where('is_active', true)
            ->first();

        if ($plan === null) {
            return;
        }

        $customerId = $event->contactId ?: $event->accountId;

        $dto = new CreateSubscriptionDTO(
            tenantId: $event->tenantId,
            companyId: $event->companyId,
            customerId: $customerId,
            planId: $plan->id,
            startDate: now()->toDateString(),
            contractTermMonths: 12,
            autoRenew: true,
            billingDayOfMonth: (int) now()->format('j'),
            paymentTermsDays: 30,
            paymentMethod: 'sepa_direct_debit',
            metadata: [
                'origin' => 'crm_opportunity',
                'opportunity_id' => $event->opportunityId,
                'won_amount' => $event->amount,
            ]
        );

        $this->lifecycleService->createSubscription($dto, $event->actorUserId);
    }
}
