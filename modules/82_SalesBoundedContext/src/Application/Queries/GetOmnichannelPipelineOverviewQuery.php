<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Queries;

final readonly class GetOmnichannelPipelineOverviewQuery
{
    public function __construct(
        public string $tenantId,
    ) {}
}
