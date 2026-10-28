<?php

declare(strict_types=1);

namespace Modules\Treasury\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class PaymentScheduledEvent implements DomainEventInterface
{
    public function __construct(
        public string $paymentBatchId,
        public string $bankAccountId,
        public float $totalAmount,
        public string $currency,
        public string $executionDate,
        public int $itemCount,
        public string $tenantId,
        public string $companyId,
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'payment_batch';
    }

    public function getAggregateId(): string
    {
        return $this->paymentBatchId;
    }

    public function getEventName(): string
    {
        return 'treasury.payment.scheduled';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function toPayload(): array
    {
        return get_object_vars($this);
    }
}
