<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * Raised when a standard-cost item is received at a price different from its standard cost.
 * Inventory stays at standard; the General Ledger books the purchase price variance (PPV)
 * against inventory with source_document_type `inventory_ppv`.
 */
final readonly class PurchasePriceVarianceRecordedEvent implements DomainEventInterface
{
    /**
     * @param  list<array{role: string, account_id: string|null, debit: string, credit: string, description: string}>  $legs
     */
    public function __construct(
        public string $movementId,
        public string $stockItemId,
        public string $quantity,
        public string $standardUnitCost,
        public string $actualUnitCost,
        public string $varianceAmount,
        public string $postingDate,
        public array $legs,
        public string $tenantId,
        public string $companyId = '',
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function sourceDocumentType(): string
    {
        return 'inventory_ppv';
    }

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'stock_movement';
    }

    public function getAggregateId(): string
    {
        return $this->movementId;
    }

    public function getEventName(): string
    {
        return 'inventory.purchase_price_variance_recorded';
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

    public function toPayload(): array
    {
        return [
            'movement_id' => $this->movementId,
            'stock_item_id' => $this->stockItemId,
            'quantity' => $this->quantity,
            'standard_unit_cost' => $this->standardUnitCost,
            'actual_unit_cost' => $this->actualUnitCost,
            'variance_amount' => $this->varianceAmount,
            'posting_date' => $this->postingDate,
            'legs' => $this->legs,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
