<?php

declare(strict_types=1);

namespace Modules\Planning\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Planning\Contracts\DemandForecastSource;
use Modules\Planning\Models\PlanningApproval;
use Modules\Planning\Models\PlanningCapacityConstraint;
use Modules\Planning\Models\PlanningItemResourceRate;
use Modules\Planning\Models\PlanningPlanLine;
use Modules\Planning\Models\PlanningPublication;
use Modules\Planning\Models\PlanningResource;
use Modules\Planning\Models\PlanningScenario;
use Modules\Planning\Policies\PlanningPolicy;
use Modules\Planning\Services\ManufacturingDemandForecastSource;

/**
 * Registers the routes, the policy and the forecast source of the Planning (S&OP) module.
 */
final class PlanningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DemandForecastSource::class, ManufacturingDemandForecastSource::class);
    }

    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([
            PlanningScenario::class,
            PlanningPlanLine::class,
            PlanningResource::class,
            PlanningItemResourceRate::class,
            PlanningCapacityConstraint::class,
            PlanningApproval::class,
            PlanningPublication::class,
        ] as $model) {
            Gate::policy($model, PlanningPolicy::class);
        }
    }
}
