<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Application\Queries;

final readonly class GetRfmMatrixQuery
{
    public function __construct(
        public string $tenantId,
        public string $segmentId,
    ) {}
}
