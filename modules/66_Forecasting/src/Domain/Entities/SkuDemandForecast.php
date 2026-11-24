<?php

declare(strict_types=1);

namespace Modules\Forecasting\Domain\Entities;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Demand forecast of a product in a warehouse and the replenishment thresholds that follow from it.
 */
final readonly class SkuDemandForecast
{
    /** Days a monthly demand figure is spread over to get the demand per day. */
    public const int DaysPerMonth = 30;

    public function __construct(
        public string $id,
        public string $tenantId,
        public string $productId,
        public string $warehouseId,
        public DateTimeImmutable $forecastPeriodStart,
        public DateTimeImmutable $forecastPeriodEnd,
        public float $predictedDemandQty,
        public float $confidenceLowerBound,
        public float $confidenceUpperBound,
        public float $recommendedReorderPoint,
        public float $recommendedSafetyStock,
        public float $seasonalityIndex,
        public float $supplierLeadTimeAvgDays,
        public float $supplierLeadTimeStdDevDays,
        public ?DateTimeImmutable $appliedToWmsAt = null,
    ) {}

    /**
     * SS = Z * sqrt(LT * sigma_D^2 + D_avg^2 * sigma_LT^2), with demand per day and lead time in days.
     */
    public static function safetyStock(float $zScore, float $leadTimeDays, float $demandStdDevPerDay, float $averageDemandPerDay, float $leadTimeStdDevDays): float
    {
        if ($leadTimeDays < 0.0 || $demandStdDevPerDay < 0.0 || $averageDemandPerDay < 0.0 || $leadTimeStdDevDays < 0.0) {
            throw new InvalidArgumentException('Lead time, demand and their deviations cannot be negative.');
        }

        return $zScore * sqrt($leadTimeDays * $demandStdDevPerDay ** 2 + $averageDemandPerDay ** 2 * $leadTimeStdDevDays ** 2);
    }

    /**
     * ROP = D_avg * LT + SS.
     */
    public static function reorderPoint(float $averageDemandPerDay, float $leadTimeDays, float $safetyStock): float
    {
        return $averageDemandPerDay * $leadTimeDays + $safetyStock;
    }

    public function isApplied(): bool
    {
        return $this->appliedToWmsAt !== null;
    }
}
