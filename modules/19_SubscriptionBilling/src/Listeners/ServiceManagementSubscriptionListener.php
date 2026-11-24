<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Listeners;

use Enterprise\EventBus\Models\TransactionalInbox;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Events\CustomerSatisfactionUpdatedEvent;
use Modules\ServiceManagement\Models\CSATSurvey;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\WorkOrder;
use Modules\SubscriptionBilling\Models\ChurnPrediction;
use Modules\SubscriptionBilling\Models\Subscription;

/**
 * A very low satisfaction score (1-2 of 5) flags a churn risk on the live subscriptions of the customer who gave it,
 * found through the survey, its ticket or its work order. No customer, no flag. Processed once per event.
 */
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

        if ($event->score > 2) {
            return;
        }

        $customerId = $this->customerOf($event);

        if ($customerId === null) {
            return;
        }

        $subscriptions = Subscription::withoutGlobalScopes()
            ->where('tenant_id', $event->tenantId)
            ->where('customer_id', $customerId)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->get();

        foreach ($subscriptions as $subscription) {
            ChurnPrediction::create([
                'tenant_id' => $event->tenantId,
                'subscription_id' => $subscription->id,
                'churn_probability_score' => number_format((5 - $event->score) * 25, 2, '.', ''),
                'risk_tier' => $event->score <= 1 ? 'critical' : 'high',
                'primary_churn_factor' => 'Low CSAT rating ('.$event->score.'/5)',
                'ai_mitigation_recommendation' => 'Trigger a Customer Success check-in.',
                'analyzed_at' => now(),
            ]);
        }
    }

    private function customerOf(CustomerSatisfactionUpdatedEvent $event): ?string
    {
        $customer = CSATSurvey::withoutGlobalScopes()->whereKey($event->csatSurveyId)->value('customer_id');

        if ($customer === null && $event->ticketId !== null) {
            $customer = Ticket::withoutGlobalScopes()->whereKey($event->ticketId)->value('customer_id');
        }

        if ($customer === null && $event->workOrderId !== null) {
            $ticketId = WorkOrder::withoutGlobalScopes()->whereKey($event->workOrderId)->value('ticket_id');
            $customer = $ticketId !== null ? Ticket::withoutGlobalScopes()->whereKey($ticketId)->value('customer_id') : null;
        }

        return $customer !== null ? (string) $customer : null;
    }
}
