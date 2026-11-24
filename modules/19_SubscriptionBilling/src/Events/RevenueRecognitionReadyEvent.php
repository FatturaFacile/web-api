<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final class RevenueRecognitionReadyEvent implements DomainEventInterface
{
    public string $eventId;

    public string $occurredAt;

    public function __construct(
        public string $recognitionEntryId,
        public string $subscriptionId,
        public float $amount,
        public string $currency,
        public string $debitAccount, // Dr Deferred Revenue
        public string $creditAccount, // Cr Subscription Revenue
        public string $recognitionDate,
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
        return 'sub_accounting';
    }

    public function getAggregateId(): string
    {
        return $this->recognitionEntryId;
    }

    public function getEventName(): string
    {
        return 'subscription.accounting.revenue_recognition_ready';
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

    public function toPayload(): array
    {
        return [
            'recognition_entry_id' => $this->recognitionEntryId,
            'subscription_id' => $this->subscriptionId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'debit_account' => $this->debitAccount,
            'credit_account' => $this->creditAccount,
            'recognition_date' => $this->recognitionDate,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
