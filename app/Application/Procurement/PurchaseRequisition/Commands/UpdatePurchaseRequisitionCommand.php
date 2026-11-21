<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Commands;

/**
 * Command to update a Purchase Requisition while in draft or budget_exceeded_blocked state.
 */
final readonly class UpdatePurchaseRequisitionCommand
{
    /**
     * @param  list<array{item_type?: string, catalog_item_id?: ?string, description?: string, quantity: string|int|float, unit_of_measure?: string, estimated_unit_price?: string|int|float, cost_center_id?: ?string, project_id?: ?string}>|null  $lines
     */
    public function __construct(
        public string $tenantId,
        public string $requisitionId,
        public string $actorUserId,
        public ?string $departmentId,
        public bool $hasDepartmentId,
        public ?string $costCenterId,
        public bool $hasCostCenterId,
        public ?string $projectId,
        public bool $hasProjectId,
        public ?string $urgency,
        public ?string $requestedDeliveryDate,
        public bool $hasRequestedDeliveryDate,
        public ?string $economicJustification,
        public bool $hasEconomicJustification,
        public ?string $currency,
        public ?string $title,
        public bool $hasTitle,
        public ?string $notes,
        public bool $hasNotes,
        public ?array $lines,
    ) {}
}
