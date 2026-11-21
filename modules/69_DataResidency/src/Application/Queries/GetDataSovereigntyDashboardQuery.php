<?php

declare(strict_types=1);

namespace Modules\DataResidency\Application\Queries;

final readonly class GetDataSovereigntyDashboardQuery
{
    public function __construct(public string $tenantId, public int $auditLimit = 20) {}
}
