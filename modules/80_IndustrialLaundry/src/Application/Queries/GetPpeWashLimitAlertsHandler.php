<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

use Modules\IndustrialLaundry\Services\PpeLimitService;

final class GetPpeWashLimitAlertsHandler
{
    public function __construct(private readonly PpeLimitService $ppe) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(GetPpeWashLimitAlertsQuery $query): array
    {
        return $this->ppe->alerts($query->customerId, $query->limitReachedOnly);
    }
}
