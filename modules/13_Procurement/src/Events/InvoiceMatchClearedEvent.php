<?php

declare(strict_types=1);

namespace Modules\Procurement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * An invoice cleared the matching (matched, within tolerance, resolved or overridden): it is released into the payment
 * schedule and its GR/IR accrual can be reversed on posting.
 */
final readonly class InvoiceMatchClearedEvent implements DomainEventInterface
{
    public function __construct(
        public string $runId,
        public string $passiveInvoiceId,
        public ?string $purchaseOrderId,
        public string $status,
        public string $grIrAmount,
        public string $tenantId,
        public string $companyId,
        public string $eventId = '',
        public string $occurredAt = '',
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'invoice_matching_run';
    }

    public function getAggregateId(): string
    {
        return $this->runId;
    }

    public function getEventName(): string
    {
        return 'procurement.invoice_match.cleared';
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
            'run_id' => $this->runId,
            'passive_invoice_id' => $this->passiveInvoiceId,
            'purchase_order_id' => $this->purchaseOrderId,
            'status' => $this->status,
            'gr_ir_amount' => $this->grIrAmount,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
