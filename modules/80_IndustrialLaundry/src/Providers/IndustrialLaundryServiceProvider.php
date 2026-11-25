<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\IndustrialLaundry\Compliance\ChItLaundryBillingStrategy;
use Modules\IndustrialLaundry\Compliance\ComplianceStrategyRegistry;
use Modules\IndustrialLaundry\Compliance\En14065RabcAndPpeWashLimitStrategy;
use Modules\IndustrialLaundry\Contracts\LaundryDeliveryNoteWriterInterface;
use Modules\IndustrialLaundry\Models\BillingRun;
use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Models\ParContract;
use Modules\IndustrialLaundry\Models\RollCage;
use Modules\IndustrialLaundry\Models\ScanSession;
use Modules\IndustrialLaundry\Models\WashBatch;
use Modules\IndustrialLaundry\Policies\IndustrialLaundryPolicy;
use Modules\IndustrialLaundry\Services\SalesDeliveryNoteWriter;

/**
 * Mod 177 — industrial laundries and linen / workwear rental: RFID UHF tunnel gates, par stock contracts, PPE wash limits,
 * UNI EN 14065 RABC certification and the monthly wash + rental billing (Issue #423).
 */
final class IndustrialLaundryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LaundryDeliveryNoteWriterInterface::class, SalesDeliveryNoteWriter::class);

        $this->app->singleton(ComplianceStrategyRegistry::class, fn (Application $app): ComplianceStrategyRegistry => new ComplianceStrategyRegistry([
            $app->make(En14065RabcAndPpeWashLimitStrategy::class),
            $app->make(ChItLaundryBillingStrategy::class),
        ]));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([Garment::class, ParContract::class, ScanSession::class, WashBatch::class, BillingRun::class, RollCage::class] as $model) {
            Gate::policy($model, IndustrialLaundryPolicy::class);
        }
    }
}
