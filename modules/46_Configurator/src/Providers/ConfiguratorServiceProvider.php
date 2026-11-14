<?php

declare(strict_types=1);

namespace Modules\Configurator\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Configurator\Models\ConfigurableModel;
use Modules\Configurator\Models\ConfiguratorOption;
use Modules\Configurator\Models\ConfiguratorOptionGroup;
use Modules\Configurator\Models\ConfiguratorRule;
use Modules\Configurator\Models\ProductConfiguration;
use Modules\Configurator\Policies\ConfiguratorPolicy;

/**
 * Registers the routes and policies of the configure-price-quote module.
 */
final class ConfiguratorServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([ConfigurableModel::class, ConfiguratorOptionGroup::class, ConfiguratorOption::class, ConfiguratorRule::class, ProductConfiguration::class] as $model) {
            Gate::policy($model, ConfiguratorPolicy::class);
        }
    }
}
