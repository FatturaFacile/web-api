<?php

declare(strict_types=1);

namespace Modules\Controlling\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Controlling\Console\RebuildFactsCommand;
use Modules\Controlling\Console\VerifyIntegrityCommand;
use Modules\Controlling\Models\AnalyticalCostCenter;
use Modules\Controlling\Models\AnalyticalJob;
use Modules\Controlling\Models\AnalyticalLedgerEntry;
use Modules\Controlling\Models\BudgetLine;
use Modules\Controlling\Models\BudgetPlan;
use Modules\Controlling\Models\CostAllocationRule;
use Modules\Controlling\Models\CostAllocationRun;
use Modules\Controlling\Models\FinancialSnapshot;
use Modules\Controlling\Models\SalesMarginFact;
use Modules\Controlling\Policies\ControllingPolicy;

/**
 * Registers the routes, the policy and the commands of the Controlling (corporate performance management) module.
 */
final class ControllingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([AnalyticalCostCenter::class, AnalyticalJob::class, AnalyticalLedgerEntry::class, CostAllocationRule::class, CostAllocationRun::class, SalesMarginFact::class, FinancialSnapshot::class, BudgetPlan::class, BudgetLine::class] as $model) {
            Gate::policy($model, ControllingPolicy::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([RebuildFactsCommand::class, VerifyIntegrityCommand::class]);
        }
    }
}
