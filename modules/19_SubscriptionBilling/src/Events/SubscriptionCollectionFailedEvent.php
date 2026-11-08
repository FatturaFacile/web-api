<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * The collection of a subscription invoice failed (a returned direct debit, a declined card) or its scheduled retry is
 * due: Treasury re-presents the collection on `nextRetryAt`. `retryDue` is true when the event announces the retry.
 */
final class SubscriptionCollectionFailedEvent implements DomainEventInterface
{
    public string $eventId;

    public string $occurredAt;

    public function __construct(
        public string $subscriptionId,
        public string $invoiceId,
        public string $dunningRecordId,
        public int $retryCount,
        public ?string $nextRetryAt,
        public ?string $reason,
        public bool $retryDue,
        public string $tenantId,
        public string $companyId,
        ?string $eventId = null,
        ?string $occurredAt = null
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid7();
        $this->occurredAt = $occurredAt ?? now()->toIso8601String();
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getAggregateType(): string
    {
        return 'sub_subscription';
    }

    public function getAggregateId(): string
    {
        return $this->subscriptionId;
    }

    public function getEventName(): string
    {
        return $this->retryDue ? 'subscription.collection.retry_due' : 'subscription.collection.failed';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getOccurredAt(): string
    {
        return $this->occurredAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'subscription_id' => $this->subscriptionId,
            'invoice_id' => $this->invoiceId,
            'dunning_record_id' => $this->dunningRecordId,
            'retry_count' => $this->retryCount,
            'next_retry_at' => $this->nextRetryAt,
            'reason' => $this->reason,
            'retry_due' => $this->retryDue,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
