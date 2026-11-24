<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Queries;

/**
 * Query for paginated and filtered Purchase Requisitions list.
 */
final readonly class GetPurchaseRequisitionsQuery
{
    public function __construct(
        public string $tenantId,
        public ?string $status = null,
        public ?string $costCenterId = null,
        public ?string $projectId = null,
        public ?string $urgency = null,
        public ?string $requesterId = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public ?string $search = null,
        public int $page = 1,
        public int $perPage = 20,
    ) {}
}
