<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Queries;

final readonly class GetDashboardQuery
{
    public function __construct(
        public string $tenantId,
        public string $asOf,
    ) {}
}
