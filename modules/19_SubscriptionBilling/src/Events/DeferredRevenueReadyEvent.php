<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final class DeferredRevenueReadyEvent implements DomainEventInterface
{
    public string $eventId;

    public string $occurredAt;

    public function __construct(
        public string $invoiceId,
        public string $subscriptionId,
        public float $deferredAmount,
        public string $currency,
        public string $debitAccount, // Dr Accounts Receivable
        public string $creditAccount, // Cr Deferred Revenue
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
        return $this->invoiceId;
    }

    public function getEventName(): string
    {
        return 'subscription.accounting.deferred_revenue_ready';
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
            'invoice_id' => $this->invoiceId,
            'subscription_id' => $this->subscriptionId,
            'deferred_amount' => $this->deferredAmount,
            'currency' => $this->currency,
            'debit_account' => $this->debitAccount,
            'credit_account' => $this->creditAccount,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
