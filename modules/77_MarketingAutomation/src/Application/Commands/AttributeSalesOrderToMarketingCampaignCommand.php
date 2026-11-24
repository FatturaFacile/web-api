<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Application\Commands;

/**
 * Attributes a quote, sales order or invoice (documentType: quote | sales_order | invoice) to a marketing campaign.
 */
final readonly class AttributeSalesOrderToMarketingCampaignCommand
{
    public function __construct(
        public string $tenantId,
        public string $documentType,
        public string $documentId,
    ) {}
}
