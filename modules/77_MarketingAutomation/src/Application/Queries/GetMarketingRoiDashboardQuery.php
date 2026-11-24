<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Application\Queries;

final readonly class GetMarketingRoiDashboardQuery
{
    public function __construct(
        public string $tenantId,
        public ?string $currency = null,
        public ?string $campaignId = null,
    ) {}
}
