<?php

declare(strict_types=1);

namespace Modules\Procurement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * A milestone of a purchase order beyond approval and receiving (dispatched, supplier confirmed, reminder sent,
 * dropship delivered, billing authorised). `$name` is the event name published on the bus, for example
 * `procurement.purchase_order.confirmed`; other modules (MRP, sales cycle, active invoicing) subscribe to it.
 */
final readonly class PurchaseOrderMilestoneEvent implements DomainEventInterface
{
    public const string Dispatched = 'procurement.purchase_order.dispatched';

    public const string Confirmed = 'procurement.purchase_order.confirmed';

    public const string ReminderSent = 'procurement.purchase_order.reminder_sent';

    public const string DropshipDelivered = 'procurement.dropship.delivered';

    public const string BillingAuthorized = 'procurement.dropship.billing_authorized';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $name,
        public string $purchaseOrderId,
        public string $poNumber,
        public string $tenantId,
        public string $companyId,
        public array $data = [],
        public string $eventId = '',
        public string $occurredAt = '',
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'purchase_order';
    }

    public function getAggregateId(): string
    {
        return $this->purchaseOrderId;
    }

    public function getEventName(): string
    {
        return $this->name;
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
        return $this->occurredAt !== '' ? $this->occurredAt : now()->toIso8601String();
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'purchase_order_id' => $this->purchaseOrderId,
            'po_number' => $this->poNumber,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            ...$this->data,
        ];
    }
}
