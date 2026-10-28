<?php

declare(strict_types=1);

namespace Modules\Treasury\Actions;

use Modules\Treasury\Contracts\LiquidityManagementServiceInterface;
use Modules\Treasury\Models\WorkingCapitalMetric;

final readonly class CalculateWorkingCapitalAction
{
    public function __construct(
        private LiquidityManagementServiceInterface $liquidityService
    ) {}

    public function execute(string $tenantId, ?string $metricDate = null, ?string $companyId = null): WorkingCapitalMetric
    {
        return $this->liquidityService->calculateWorkingCapital($tenantId, $metricDate, $companyId);
    }
}
