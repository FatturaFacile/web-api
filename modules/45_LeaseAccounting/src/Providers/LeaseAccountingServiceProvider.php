<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\LeaseAccounting\Models\LeaseContract;
use Modules\LeaseAccounting\Models\LeasePeriodPosting;
use Modules\LeaseAccounting\Models\LeaseScheduleLine;
use Modules\LeaseAccounting\Models\LeaseScheduleVersion;
use Modules\LeaseAccounting\Policies\LeaseAccountingPolicy;

/**
 * Registers the routes and policies of the lease accounting module (IFRS 16 / OIC / Swiss GAAP FER).
 */
final class LeaseAccountingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([LeaseContract::class, LeaseScheduleVersion::class, LeaseScheduleLine::class, LeasePeriodPosting::class] as $model) {
            Gate::policy($model, LeaseAccountingPolicy::class);
        }
    }
}
