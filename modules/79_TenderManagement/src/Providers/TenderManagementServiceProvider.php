<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\TenderManagement\Console\ScanTenderAlertsCommand;
use Modules\TenderManagement\Contracts\CompanyCapabilityProviderInterface;
use Modules\TenderManagement\Models\CompetitorBenchmark;
use Modules\TenderManagement\Models\QualificationVaultDocument;
use Modules\TenderManagement\Models\TenderAlert;
use Modules\TenderManagement\Models\TenderNotice;
use Modules\TenderManagement\Models\TenderParticipation;
use Modules\TenderManagement\Policies\TenderManagementPolicy;
use Modules\TenderManagement\Services\ErpCompanyCapabilityProvider;
use Modules\TenderManagement\Strategies\TenderStrategyRegistry;

/**
 * Mod 169 — Public and private tender management (Issue #415).
 */
final class TenderManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenderStrategyRegistry::class);
        $this->app->bind(CompanyCapabilityProviderInterface::class, ErpCompanyCapabilityProvider::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([TenderNotice::class, TenderParticipation::class, QualificationVaultDocument::class, CompetitorBenchmark::class, TenderAlert::class] as $model) {
            Gate::policy($model, TenderManagementPolicy::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ScanTenderAlertsCommand::class]);
        }
    }
}
