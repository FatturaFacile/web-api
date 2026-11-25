<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

final readonly class GetBillingRunsQuery
{
    public function __construct(public ?string $customerId = null, public ?string $status = null, public int $perPage = 25) {}
}
