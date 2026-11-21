<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Commands;

/**
 * Command to create a draft Mod 044 Purchase Requisition with catalog and/or free-text lines.
 */
final readonly class CreatePurchaseRequisitionCommand
{
    /**
     * @param  list<array{item_type?: string, catalog_item_id?: ?string, description?: string, quantity: string|int|float, unit_of_measure?: string, estimated_unit_price?: string|int|float, cost_center_id?: ?string, project_id?: ?string}>  $lines
     */
    public function __construct(
        public string $tenantId,
        public string $requesterUserId,
        public ?string $departmentId,
        public ?string $costCenterId,
        public ?string $projectId,
        public string $urgency,
        public ?string $requestedDeliveryDate,
        public ?string $economicJustification,
        public string $currency,
        public ?string $title,
        public ?string $notes,
        public array $lines,
    ) {}
}
