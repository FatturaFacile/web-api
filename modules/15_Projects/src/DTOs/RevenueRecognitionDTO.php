<?php

declare(strict_types=1);

namespace Modules\Projects\DTOs;

/**
 * A revenue recognition run for an accounting date. The completion percentage can be overridden (for instance from a
 * surveyor's certificate, SAL) only under the percentage-of-completion method.
 */
final readonly class RevenueRecognitionDTO
{
    public function __construct(
        public string $projectId,
        public string $accountingPeriodDate,
        public ?string $overrideCompletionPercentage = null,
        public ?string $userId = null
    ) {}
}
