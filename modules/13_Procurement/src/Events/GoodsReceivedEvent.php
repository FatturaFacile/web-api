<?php

declare(strict_types=1);

namespace Modules\Procurement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class GoodsReceivedEvent implements DomainEventInterface
{
    /**
     * @param  array<int, array{purchase_order_line_id: string, quantity_received: float, quantity_accepted: float, lot_number?: ?string}>  $receivedLines
     */
    public function __construct(
        public string $receiptId,
        public string $receiptNumber,
        public string $purchaseOrderId,
        public string $supplierDeliveryNoteRef,
        public array $receivedLines,
        public string $tenantId,
        public string $companyId,
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getEventName(): string
    {
        return 'procurement.goods_received';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): ?string
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
            'receipt_id' => $this->receiptId,
            'receipt_number' => $this->receiptNumber,
            'purchase_order_id' => $this->purchaseOrderId,
            'supplier_delivery_note_ref' => $this->supplierDeliveryNoteRef,
            'received_lines' => $this->receivedLines,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
