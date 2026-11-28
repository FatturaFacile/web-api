<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\DairyCheeseManufacturing\Models\AgingCareLog;
use Modules\DairyCheeseManufacturing\Models\AgingRack;
use Modules\DairyCheeseManufacturing\Models\Breeder;
use Modules\DairyCheeseManufacturing\Models\CheeseAgingWheel;
use Modules\DairyCheeseManufacturing\Models\CheeseVatBatch;
use Modules\DairyCheeseManufacturing\Models\MilkDelivery;
use Modules\DairyCheeseManufacturing\Models\MilkTank;
use Modules\DairyCheeseManufacturing\Models\MonthlySettlement;
use Modules\DairyCheeseManufacturing\Models\PricingGrid;
use Modules\DairyCheeseManufacturing\Models\QualityTest;
use Modules\DairyCheeseManufacturing\Models\RecallReport;
use Modules\DairyCheeseManufacturing\Models\RegulatorySubmission;
use Modules\DairyCheeseManufacturing\Models\WeightLossCurve;
use Modules\DairyCheeseManufacturing\Policies\DairyCheesePolicy;
use Modules\DairyCheeseManufacturing\Services\WheelMetrics;

/**
 * Mod 298 — Dairy, cooperative creamery and alpine pasture vertical (Issue #544).
 */
final class DairyCheeseManufacturingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The curves are cached per request (and per tenant): a scoped binding is reset between requests and queue jobs.
        $this->app->scoped(WheelMetrics::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([
            Breeder::class, MilkTank::class, MilkDelivery::class, QualityTest::class, PricingGrid::class, MonthlySettlement::class, CheeseVatBatch::class,
            AgingRack::class, CheeseAgingWheel::class, WeightLossCurve::class, AgingCareLog::class, RegulatorySubmission::class, RecallReport::class,
        ] as $model) {
            Gate::policy($model, DairyCheesePolicy::class);
        }
    }
}
