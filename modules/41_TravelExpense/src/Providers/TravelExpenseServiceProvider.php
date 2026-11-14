<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\TravelExpense\Models\TravelMileageRate;
use Modules\TravelExpense\Models\TravelPerDiemRate;
use Modules\TravelExpense\Models\TravelTrip;
use Modules\TravelExpense\Policies\TravelRatePolicy;
use Modules\TravelExpense\Policies\TravelTripPolicy;

/**
 * Registers the routes and policies of the business trip and travel expense module.
 */
final class TravelExpenseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        Gate::policy(TravelTrip::class, TravelTripPolicy::class);
        Gate::policy(TravelPerDiemRate::class, TravelRatePolicy::class);
        Gate::policy(TravelMileageRate::class, TravelRatePolicy::class);
    }
}
