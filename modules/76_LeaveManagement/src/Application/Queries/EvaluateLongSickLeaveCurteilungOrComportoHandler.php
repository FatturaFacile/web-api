<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Application\Queries;

use Modules\LeaveManagement\Domain\ValueObjects\ComportoOrCurtailmentEvaluation;
use Modules\LeaveManagement\Services\LeaveComportoAndCurtailmentService;

final readonly class EvaluateLongSickLeaveCurteilungOrComportoHandler
{
    public function __construct(
        private LeaveComportoAndCurtailmentService $service,
    ) {}

    public function handle(EvaluateLongSickLeaveCurteilungOrComportoQuery $query): ComportoOrCurtailmentEvaluation
    {
        return $this->service->evaluate(
            tenantId: $query->tenantId,
            employeeId: $query->employeeId,
            fiscalYear: $query->fiscalYear,
            countryOverride: $query->country,
            applyCurtailment: $query->applyCurtailment,
        );
    }
}
