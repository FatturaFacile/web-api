<?php

declare(strict_types=1);

namespace Modules\Budgeting\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Budgeting\Contracts\LedgerLookup;
use Modules\Budgeting\Models\BudgetApproval;
use Modules\Budgeting\Models\BudgetDriver;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetVersion;
use Modules\Budgeting\Policies\BudgetingPolicy;
use Modules\Budgeting\Services\GeneralLedgerLookup;

/**
 * Registers the routes, the policy and the ledger lookup of the Budgeting (planning and budgeting) module.
 */
final class BudgetingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LedgerLookup::class, GeneralLedgerLookup::class);
    }

    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([BudgetVersion::class, BudgetLine::class, BudgetDriver::class, BudgetApproval::class] as $model) {
            Gate::policy($model, BudgetingPolicy::class);
        }
    }
}
