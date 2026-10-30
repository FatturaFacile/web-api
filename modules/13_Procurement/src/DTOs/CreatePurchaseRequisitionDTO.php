<?php

declare(strict_types=1);

namespace Modules\Procurement\DTOs;

final readonly class CreatePurchaseRequisitionDTO
{
    public string $requestorUserId;

    /**
     * @param  array<int, array{description: string, quantity_requested?: float, quantity?: float, uom?: string, estimated_unit_price: float, supplier_catalog_id?: ?string, suggested_supplier_id?: ?string}>  $lines
     */
    public function __construct(
        public string $title,
        ?string $requestorUserId = null,
        public string $requiredByDate = '',
        public array $lines = [],
        public string $urgency = 'medium',
        public ?string $requisitionNumber = null,
        public ?string $projectId = null,
        public ?string $costCenterId = null,
        public ?string $departmentId = null,
        public ?string $branchId = null,
        public string $currency = 'EUR',
        public ?string $notes = null,
        public ?string $tenantId = null,
        public ?string $companyId = null,
        public ?string $userId = null
    ) {
        $this->requestorUserId = $requestorUserId ?? (string) $this->userId;
    }
}
