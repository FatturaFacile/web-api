<?php

declare(strict_types=1);

namespace Modules\Projects\DTOs;

final readonly class RevenueRecognitionDTO
{
    public function __construct(
        public string $projectId,
        public string $accountingPeriodDate,
        public ?float $overrideCompletionPercentage = null,
        public ?string $userId = null
    ) {}
}
