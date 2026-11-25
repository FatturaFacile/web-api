<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Queries;

final readonly class GetCustomerHistoryQuery
{
    public function __construct(
        public string $customerId,
        public string $asOf,
    ) {}
}
