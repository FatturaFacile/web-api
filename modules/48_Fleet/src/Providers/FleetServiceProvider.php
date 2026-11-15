<?php

declare(strict_types=1);

namespace Modules\Fleet\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Fleet\Models\FleetDeadline;
use Modules\Fleet\Models\FleetFuelEntry;
use Modules\Fleet\Models\FleetIncident;
use Modules\Fleet\Models\FleetScheduledService;
use Modules\Fleet\Models\FleetServiceRecord;
use Modules\Fleet\Models\FleetVehicle;
use Modules\Fleet\Models\FleetVehicleAssignment;
use Modules\Fleet\Policies\FleetPolicy;

/**
 * Registers the routes and the policy of the Fleet (company vehicles) module.
 */
final class FleetServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([
            FleetVehicle::class,
            FleetVehicleAssignment::class,
            FleetFuelEntry::class,
            FleetServiceRecord::class,
            FleetScheduledService::class,
            FleetDeadline::class,
            FleetIncident::class,
        ] as $model) {
            Gate::policy($model, FleetPolicy::class);
        }
    }
}
