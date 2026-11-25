<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

final readonly class GetPpeWashLimitAlertsQuery
{
    public function __construct(public ?string $customerId = null, public bool $limitReachedOnly = false) {}
}
