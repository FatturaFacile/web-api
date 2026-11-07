<?php

declare(strict_types=1);

namespace Modules\Workshop\Providers;

use App\Models\Vehicle;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Workshop\Models\RecurringService;
use Modules\Workshop\Models\VehicleSale;
use Modules\Workshop\Models\VehicleStockUnit;
use Modules\Workshop\Models\WorkshopFolder;
use Modules\Workshop\Models\WorkshopFolderLine;
use Modules\Workshop\Policies\WorkshopPolicy;

final class WorkshopServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([Vehicle::class, WorkshopFolder::class, WorkshopFolderLine::class, VehicleStockUnit::class, VehicleSale::class, RecurringService::class] as $model) {
            Gate::policy($model, WorkshopPolicy::class);
        }
    }
}
