<?php

declare(strict_types=1);

namespace Modules\Manufacturing\DTOs;

/**
 * Data of a new production order. The quantity is a decimal string; without a BOM or routing the revisions effective
 * on the planned start date are used.
 */
final readonly class CreateProductionOrderDTO
{
    public function __construct(
        public string $tenantId,
        public string $mfgItemId,
        public string $targetQuantity,
        public string $plannedStartDate,
        public string $plannedEndDate,
        public ?string $bomId = null,
        public ?string $routingId = null,
        public ?string $projectId = null,
        public ?string $notes = null,
        public ?string $salesOrderId = null,
        public ?string $warehouseId = null,
        public ?string $receiptWarehouseId = null,
        public ?string $plannedOrderId = null,
        public ?string $createdBy = null,
    ) {}
}
