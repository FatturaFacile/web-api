<?php

declare(strict_types=1);

namespace Modules\Sustainability\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Sustainability\Models\EsgActivityRecord;
use Modules\Sustainability\Models\EsgEmissionFactor;
use Modules\Sustainability\Models\EsgKpiDefinition;
use Modules\Sustainability\Models\EsgKpiValue;
use Modules\Sustainability\Models\EsgMaterialityAssessment;
use Modules\Sustainability\Models\EsgReductionTarget;
use Modules\Sustainability\Models\EsgReportingPeriod;
use Modules\Sustainability\Policies\SustainabilityPolicy;

/**
 * Registers the routes and the policy of the Sustainability (ESG and carbon accounting) module.
 */
final class SustainabilityServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([
            EsgEmissionFactor::class,
            EsgActivityRecord::class,
            EsgReportingPeriod::class,
            EsgReductionTarget::class,
            EsgKpiDefinition::class,
            EsgKpiValue::class,
            EsgMaterialityAssessment::class,
        ] as $model) {
            Gate::policy($model, SustainabilityPolicy::class);
        }
    }
}
