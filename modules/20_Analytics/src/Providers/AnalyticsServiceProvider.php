<?php

declare(strict_types=1);

namespace Modules\Analytics\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Analytics\Console\RefreshKpiSnapshotsCommand;
use Modules\Analytics\Console\RunScheduledExportsCommand;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Models\ExportSchedule;
use Modules\Analytics\Models\ReportDefinition;
use Modules\Analytics\Policies\DesignedReportPolicy;
use Modules\Analytics\Policies\ExportSchedulePolicy;
use Modules\Analytics\Policies\ReportDefinitionPolicy;
use Modules\Analytics\Semantic\SemanticCatalog;

/**
 * Registers the routes, policies, commands and bindings of the Analytics module.
 */
final class AnalyticsServiceProvider extends ServiceProvider
{
    /**
     * Register the bindings. The semantic catalogue is shared so other modules can extend it with their datasets.
     */
    public function register(): void
    {
        $this->app->singleton(SemanticCatalog::class);
    }

    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Gate::policy(ReportDefinition::class, ReportDefinitionPolicy::class);
        Gate::policy(DesignedReport::class, DesignedReportPolicy::class);
        Gate::policy(ExportSchedule::class, ExportSchedulePolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([RunScheduledExportsCommand::class, RefreshKpiSnapshotsCommand::class]);
        }
    }
}
