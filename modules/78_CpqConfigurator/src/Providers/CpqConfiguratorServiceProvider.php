<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\CpqConfigurator\Models\CpqCharacteristic;
use Modules\CpqConfigurator\Models\CpqCharacteristicOption;
use Modules\CpqConfigurator\Models\CpqConfiguredInstance;
use Modules\CpqConfigurator\Models\CpqConstraintRule;
use Modules\CpqConfigurator\Models\CpqModel;
use Modules\CpqConfigurator\Models\CpqPricingFormula;
use Modules\CpqConfigurator\Models\CpqRoutingMapping;
use Modules\CpqConfigurator\Models\CpqSuperBomMapping;
use Modules\CpqConfigurator\Policies\CpqConfiguratorPolicy;

/**
 * Mod 230 — technical-commercial CPQ configurator: routes and policies.
 */
final class CpqConfiguratorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([CpqModel::class, CpqCharacteristic::class, CpqCharacteristicOption::class, CpqConstraintRule::class, CpqSuperBomMapping::class, CpqRoutingMapping::class, CpqPricingFormula::class, CpqConfiguredInstance::class] as $model) {
            Gate::policy($model, CpqConfiguratorPolicy::class);
        }
    }
}
