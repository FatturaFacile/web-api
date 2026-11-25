<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

final readonly class GetDashboardQuery
{
    public function __construct(
        public string $tenantId,
        public ?string $asOf,
    ) {}
}
