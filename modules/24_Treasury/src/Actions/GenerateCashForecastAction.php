<?php

declare(strict_types=1);

namespace Modules\Treasury\Actions;

use Modules\Treasury\Contracts\CashForecastServiceInterface;
use Modules\Treasury\Models\CashForecast;

final readonly class GenerateCashForecastAction
{
    public function __construct(
        private CashForecastServiceInterface $forecastService
    ) {}

    public function execute(
        string $tenantId,
        string $scenarioCode = 'BASELINE',
        int $horizonDays = 90,
        ?string $calculationDate = null,
        ?string $companyId = null
    ): CashForecast {
        return $this->forecastService->generateForecast($tenantId, $scenarioCode, $horizonDays, $calculationDate, $companyId);
    }
}
