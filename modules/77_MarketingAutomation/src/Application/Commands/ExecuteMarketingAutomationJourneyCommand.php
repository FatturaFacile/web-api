<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Application\Commands;

/**
 * Enrols the audience of the trigger and runs every due step of an active campaign (or of all active ones).
 */
final readonly class ExecuteMarketingAutomationJourneyCommand
{
    public function __construct(
        public string $tenantId,
        public ?string $campaignId = null,
    ) {}
}
