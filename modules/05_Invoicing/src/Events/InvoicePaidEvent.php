<?php

declare(strict_types=1);

namespace Modules\Invoicing\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * A sales invoice has just become fully settled (payments and applied credit notes cover what is due). Raised by
 * InvoicePaidObserver once per transition to "paid".
 */
final readonly class InvoicePaidEvent implements DomainEventInterface
{
    public function __construct(
        public string $invoiceId,
        public string $invoiceNumber,
        public ?string $customerId,
        public string $currency,
        public float $totalGross,
        public float $totalPaid,
        public string $paidAt,
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
        return 'sales_invoice';
    }

    public function getAggregateId(): string
    {
        return $this->invoiceId;
    }

    public function getEventName(): string
    {
        return 'invoicing.invoice.paid';
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
