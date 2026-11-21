<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Queries;

/**
 * Read-only query to preview real-time budget availability against cost centers before submission.
 */
final readonly class CheckPurchaseRequisitionBudgetQuery
{
    /**
     * @param  list<array{quantity: string|int|float, estimated_unit_price: string|int|float, cost_center_id?: ?string}>  $lines
     */
    public function __construct(
        public string $tenantId,
        public ?string $costCenterId,
        public string $currency,
        public int $fiscalYear,
        public ?string $excludeRequisitionId,
        public array $lines,
    ) {}
}
