<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class MarketingCampaignExecutedEvent
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $campaignId,
        public string $rfmSegment,
        public int $eligibleRecipientsCount,
        public int $excludedInsolventCount,
        public float $roiPct,
    ) {}
}
