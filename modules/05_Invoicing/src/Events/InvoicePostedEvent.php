<?php

declare(strict_types=1);

namespace Modules\Invoicing\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class InvoicePostedEvent implements DomainEventInterface
{
    /**
     * @param  array<int, array<string, mixed>>  $revenueLines
     * @param  array<int, array<string, mixed>>  $taxLines
     */
    public function __construct(
        public string $invoiceId,
        public string $invoiceNumber,
        public string $customerId,
        public string $date,
        public ?string $dueDate,
        public string $currency,
        public float $exchangeRate,
        public float $totalNet,
        public float $totalTax,
        public float $totalGross,
        public array $revenueLines,
        public array $taxLines,
        public ?string $glJournalEntryId,
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
        return 'invoicing.invoice.posted';
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
