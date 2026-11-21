<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Queries;

final readonly class GetPredictiveDashboardQuery
{
    public function __construct(
        public string $tenantId,
        public ?int $horizonDays = null,
    ) {}
}
