<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Manufacturing\Contracts\ManufacturingForecastingServiceInterface;

/**
 * Publishes the monthly production projection of one tenant for Treasury (scheduled monthly).
 */
final class ProcessManufacturingForecastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $tenantId,
        public int $months = 6
    ) {}

    /**
     * Runs the projection.
     */
    public function handle(ManufacturingForecastingServiceInterface $forecastingService): void
    {
        $forecastingService->generateCashAndMaterialForecast($this->tenantId, $this->months);
    }
}
