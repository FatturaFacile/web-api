<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Commands;

/**
 * Command to create a draft Request for Quotation.
 */
final readonly class CreateRfqCommand
{
    /**
     * @param  list<string>  $supplierIds
     * @param  list<array{source_requisition_line_id?: ?string, item_id?: ?string, custom_item_code?: ?string, internal_sku?: ?string, description: string, technical_specifications?: ?string, quantity: string|int|float, uom?: ?string, target_unit_price?: string|int|float|null, requested_delivery_date?: ?string}>  $lines
     * @param  array{price_weight?: int, lead_time_weight?: int, supplier_rating_weight?: int}|null  $evaluationWeights
     */
    public function __construct(
        public string $tenantId,
        public ?string $actorUserId,
        public string $title,
        public ?string $description,
        public string $currencyCode,
        public ?string $incoterms,
        public string $submissionDeadline,
        public ?string $expectedDeliveryDate,
        public ?array $evaluationWeights,
        public bool $sealedBidding,
        public ?string $purchaseRequisitionId,
        public ?string $costCenterId,
        public ?string $notes,
        public array $supplierIds,
        public array $lines,
    ) {}
}
