<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Beekeeping\Models\Apiary;
use Modules\Beekeeping\Models\CensusDeclaration;
use Modules\Beekeeping\Models\ExtractionBatch;
use Modules\Beekeeping\Models\Hive;
use Modules\Beekeeping\Models\Inspection;
use Modules\Beekeeping\Models\JarringRun;
use Modules\Beekeeping\Models\NomadismMovement;
use Modules\Beekeeping\Models\SuperIntake;
use Modules\Beekeeping\Models\TaxProfile;
use Modules\Beekeeping\Models\VarroaCount;
use Modules\Beekeeping\Models\VarroaTreatment;
use Modules\Beekeeping\Policies\BeekeepingPolicy;
use Modules\Beekeeping\Services\PackagingStock;
use Modules\Beekeeping\Services\PackagingStockConsumer;

/**
 * Mod 296 — Beekeeping and honey production (Issue #542).
 */
final class BeekeepingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PackagingStock::class, PackagingStockConsumer::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([Apiary::class, Hive::class, Inspection::class, VarroaTreatment::class, VarroaCount::class, NomadismMovement::class, CensusDeclaration::class, SuperIntake::class, ExtractionBatch::class, JarringRun::class, TaxProfile::class] as $model) {
            Gate::policy($model, BeekeepingPolicy::class);
        }
    }
}
