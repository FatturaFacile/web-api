<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Queries;

/**
 * Query for full Purchase Requisition detail including lines, approval steps, immutable logs, and document links.
 */
final readonly class GetPurchaseRequisitionDetailQuery
{
    public function __construct(
        public string $tenantId,
        public string $requisitionId,
    ) {}
}
