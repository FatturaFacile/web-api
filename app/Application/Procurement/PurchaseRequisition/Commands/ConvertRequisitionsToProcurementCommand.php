<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Commands;

/**
 * Command to group and/or partially split approved Purchase Requisition lines into an RFQ (Mod 045) or PO (Mod 046).
 */
final readonly class ConvertRequisitionsToProcurementCommand
{
    /**
     * @param  list<string>|null  $supplierIds
     * @param  list<array{requisition_line_id: string, quantity: string|int|float}>  $items
     */
    public function __construct(
        public string $tenantId,
        public string $actorUserId,
        public string $targetType,
        public ?string $supplierId,
        public ?array $supplierIds,
        public ?string $title,
        public ?string $submissionDeadline,
        public ?string $expectedDeliveryDate,
        public ?string $notes,
        public array $items,
    ) {}
}
