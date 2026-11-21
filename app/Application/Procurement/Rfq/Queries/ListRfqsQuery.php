<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Queries;

/**
 * Paginated & filterable query for listing tenant RFQs.
 */
final readonly class ListRfqsQuery
{
    public function __construct(
        public ?string $status = null,
        public ?string $buyerUserId = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public ?string $search = null,
        public int $perPage = 25,
    ) {}
}
