<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Listeners;

use Enterprise\EventBus\Models\TransactionalInbox;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Events\CustomerSatisfactionUpdatedEvent;
use Modules\SubscriptionBilling\Models\ChurnPrediction;
use Modules\SubscriptionBilling\Models\Subscription;

final readonly class ServiceManagementSubscriptionListener
{
    public function handle(CustomerSatisfactionUpdatedEvent $event): void
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

        if ($event->score <= 2) {
            // Find active subscription for tenant
            $subscription = Subscription::where('tenant_id', $event->tenantId)
                ->where('status', 'active')
                ->latest()
                ->first();

            if ($subscription !== null) {
                ChurnPrediction::create([
                    'tenant_id' => $event->tenantId,
                    'subscription_id' => $subscription->id,
                    'churn_probability_score' => 78.50,
                    'risk_tier' => 'high',
                    'primary_churn_factor' => 'Low CSAT rating ('.$event->score.'/5) on field service visit',
                    'ai_mitigation_recommendation' => 'Trigger Customer Success executive check-in and apply 15% goodwill retainer credit.',
                    'analyzed_at' => now(),
                ]);
            }
        }
    }
}
