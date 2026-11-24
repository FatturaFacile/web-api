<?php

declare(strict_types=1);

namespace Modules\Treasury\Contracts;

use Modules\Treasury\Models\FxPosition;
use Modules\Treasury\Models\FxRevaluation;

interface ForeignExchangeRiskServiceInterface
{
    /**
     * Compute currency exposure matrix across all non-functional currencies.
     *
     * @return array<string, FxPosition>
     */
    public function computeCurrencyExposure(string $tenantId, ?string $asOfDate = null, ?string $companyId = null): array;

    /**
     * Perform periodic IAS 21 balance sheet revaluation and calculate unrealized FX delta.
     */
    public function performFxRevaluation(
        string $tenantId,
        string $currency,
        string $periodEndDate,
        float $spotRate,
        ?string $companyId = null,
        ?string $userId = null
    ): FxRevaluation;

    /**
     * Calculate Value-at-Risk (VaR 95% 1-day) for a foreign exchange holding.
     */
    public function calculateVar95(float $exposureAmount, float $historicalVolatility): float;
}
