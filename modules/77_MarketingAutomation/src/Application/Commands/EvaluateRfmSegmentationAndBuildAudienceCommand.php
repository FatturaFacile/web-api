<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Application\Commands;

/**
 * Computes RFM scores and rebuilds the audience of a segment (or of every hourly auto-refresh segment of the tenant).
 */
final readonly class EvaluateRfmSegmentationAndBuildAudienceCommand
{
    public function __construct(
        public string $tenantId,
        public ?string $segmentId = null,
        public bool $onlyAutoRefresh = false,
    ) {}
}
