<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Domain\ValueObjects;

/**
 * Immutable commercial document traceability chain across the Sales Bounded Context:
 * Lead -> Opportunity -> Quote -> Sales Order -> DDT/Lieferschein -> Invoice -> Settlement.
 */
final readonly class PipelineConversionChain
{
    public function __construct(
        public ?string $dossierId = null,
        public ?string $leadId = null,
        public ?string $opportunityId = null,
        public ?string $quoteId = null,
        public ?string $salesOrderId = null,
        public ?string $deliveryNoteId = null,
        public ?string $invoiceId = null,
        public ?string $settlementId = null,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'dossier_id' => $this->dossierId,
            'lead_id' => $this->leadId,
            'opportunity_id' => $this->opportunityId,
            'quote_id' => $this->quoteId,
            'sales_order_id' => $this->salesOrderId,
            'delivery_note_id' => $this->deliveryNoteId,
            'invoice_id' => $this->invoiceId,
            'settlement_id' => $this->settlementId,
        ];
    }
}
