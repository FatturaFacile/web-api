<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Application\Commands;

use Modules\MarketingAutomation\Models\Attribution;
use Modules\MarketingAutomation\Services\AttributionService;

final readonly class AttributeSalesOrderToMarketingCampaignHandler
{
    public function __construct(private AttributionService $attribution) {}

    public function handle(AttributeSalesOrderToMarketingCampaignCommand $command): ?Attribution
    {
        return $this->attribution->attribute($command->tenantId, $command->documentType, $command->documentId);
    }
}
