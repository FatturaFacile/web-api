<?php

declare(strict_types=1);

namespace Modules\Treasury\Contracts;

use Modules\Treasury\Models\CashForecast;

interface CashForecastServiceInterface
{
    /**
     * Generate rolling multi-horizon cash forecast (30, 60, 90, 180 days).
     */
    public function generateForecast(
        string $tenantId,
        string $scenarioCode = 'BASELINE',
        int $horizonDays = 90,
        ?string $calculationDate = null,
        ?string $companyId = null
    ): CashForecast;

    /**
     * Add forecast line from operational event (AR, AP, Project, Payroll).
     */
    public function recordForecastLine(
        string $cashForecastId,
        string $projectedDate,
        string $sourceCategory,
        string $flowType,
        float $expectedAmount,
        float $confidenceWeight = 1.00,
        string $currency = 'EUR',
        ?string $sourceReference = null,
        ?string $sourceEntityId = null
    ): void;

    /**
     * Evaluate cash deficit risks and trigger treasury alerts if threshold breached.
     *
     * @return array<string, mixed>
     */
    public function evaluateDeficits(string $cashForecastId): array;
}
