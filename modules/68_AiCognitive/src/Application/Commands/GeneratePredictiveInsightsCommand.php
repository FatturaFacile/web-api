<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Commands;

final readonly class GeneratePredictiveInsightsCommand
{
    public function __construct(
        public string $tenantId,
        public ?string $userId = null,
        public bool $withAnomalyScan = true,
    ) {}
}
