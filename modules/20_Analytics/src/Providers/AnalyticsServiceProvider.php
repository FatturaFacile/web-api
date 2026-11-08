<?php

declare(strict_types=1);

namespace Modules\Analytics\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Analytics\Models\ReportDefinition;
use Modules\Analytics\Policies\ReportDefinitionPolicy;

/**
 * Registers the routes, policies and bindings of the Analytics module.
 */
final class AnalyticsServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Gate::policy(ReportDefinition::class, ReportDefinitionPolicy::class);
    }
}
