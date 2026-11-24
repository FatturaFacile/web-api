<?php

declare(strict_types=1);

namespace Modules\Procurement\DTOs;

use Modules\Procurement\Enums\InvoiceMatchLineStatus;

/**
 * The outcome of the comparison of one invoice line, before it is stored.
 */
final readonly class MatchLineResult
{
    /**
     * @param  list<string>  $reasons  MatchDiscrepancyReason values
     */
    public function __construct(
        public int $lineNumber,
        public ?string $passiveInvoiceLineId,
        public ?string $purchaseOrderLineId,
        public ?string $description,
        public InvoiceMatchLineStatus $status,
        public bool $receiptRequired,
        public string $orderedQuantity,
        public string $receivedQuantity,
        public string $previouslyInvoicedQuantity,
        public string $invoicedQuantity,
        public string $orderedUnitPrice,
        public string $invoicedUnitPrice,
        public string $quantityVariance,
        public string $priceVariance,
        public string $priceVariancePercent,
        public string $amountVariance,
        public string $allowedQuantityVariance,
        public string $allowedAmountVariance,
        public string $grIrAmount,
        public ?string $toleranceRuleId,
        public array $reasons,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'line_number' => $this->lineNumber,
            'passive_invoice_line_id' => $this->passiveInvoiceLineId,
            'purchase_order_line_id' => $this->purchaseOrderLineId,
            'description' => $this->description !== null ? mb_substr($this->description, 0, 255) : null,
            'status' => $this->status->value,
            'receipt_required' => $this->receiptRequired,
            'ordered_quantity' => $this->orderedQuantity,
            'received_quantity' => $this->receivedQuantity,
            'previously_invoiced_quantity' => $this->previouslyInvoicedQuantity,
            'invoiced_quantity' => $this->invoicedQuantity,
            'ordered_unit_price' => $this->orderedUnitPrice,
            'invoiced_unit_price' => $this->invoicedUnitPrice,
            'quantity_variance' => $this->quantityVariance,
            'price_variance' => $this->priceVariance,
            'price_variance_percent' => $this->priceVariancePercent,
            'amount_variance' => $this->amountVariance,
            'allowed_quantity_variance' => $this->allowedQuantityVariance,
            'allowed_amount_variance' => $this->allowedAmountVariance,
            'gr_ir_amount' => $this->grIrAmount,
            'tolerance_rule_id' => $this->toleranceRuleId,
            'reasons' => $this->reasons,
        ];
    }
}
