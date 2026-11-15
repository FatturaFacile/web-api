<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\BusinessIntelligence\Console\RunSnapshotsCommand;
use Modules\BusinessIntelligence\Models\BiCalculatedMeasure;
use Modules\BusinessIntelligence\Models\BiDashboard;
use Modules\BusinessIntelligence\Models\BiDashboardWidget;
use Modules\BusinessIntelligence\Models\BiSavedQuery;
use Modules\BusinessIntelligence\Models\BiShare;
use Modules\BusinessIntelligence\Models\BiSnapshot;
use Modules\BusinessIntelligence\Policies\BiPolicy;
use Modules\BusinessIntelligence\Security\RowLevelSecurityRegistry;

/**
 * Registers the routes, the policy, the command and the row-level security registry of the Business Intelligence module.
 */
final class BusinessIntelligenceServiceProvider extends ServiceProvider
{
    /**
     * Register the bindings. The registry is shared so other modules can add their row-level security rules.
     */
    public function register(): void
    {
        $this->app->singleton(RowLevelSecurityRegistry::class);
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
            BiCalculatedMeasure::class,
            BiSavedQuery::class,
            BiDashboard::class,
            BiDashboardWidget::class,
            BiShare::class,
            BiSnapshot::class,
        ] as $model) {
            Gate::policy($model, BiPolicy::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([RunSnapshotsCommand::class]);
        }
    }
}
