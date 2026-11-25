<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Queries;

final readonly class GetCustomer360SummaryQuery
{
    public function __construct(
        public string $tenantId,
        public string $dossierId,
    ) {}
}
