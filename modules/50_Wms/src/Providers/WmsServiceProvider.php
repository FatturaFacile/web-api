<?php

declare(strict_types=1);

namespace Modules\Wms\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Wms\Contracts\WmsStockGateway;
use Modules\Wms\Models\WmsCrossDockLine;
use Modules\Wms\Models\WmsCrossDockOrder;
use Modules\Wms\Models\WmsDockAppointment;
use Modules\Wms\Models\WmsDockDoor;
use Modules\Wms\Models\WmsSlottingRecommendation;
use Modules\Wms\Models\WmsSlottingRule;
use Modules\Wms\Models\WmsTask;
use Modules\Wms\Models\WmsWave;
use Modules\Wms\Models\WmsWaveRule;
use Modules\Wms\Policies\WmsPolicy;
use Modules\Wms\Services\InventoryWmsStockGateway;

/**
 * Registers the routes and the policy of the Warehouse management (WMS) module.
 */
final class WmsServiceProvider extends ServiceProvider
{
    /**
     * Binds the stock gateway to the inventory module.
     */
    public function register(): void
    {
        $this->app->bind(WmsStockGateway::class, InventoryWmsStockGateway::class);
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
            WmsDockDoor::class,
            WmsWaveRule::class,
            WmsWave::class,
            WmsTask::class,
            WmsSlottingRule::class,
            WmsSlottingRecommendation::class,
            WmsCrossDockOrder::class,
            WmsCrossDockLine::class,
            WmsDockAppointment::class,
        ] as $model) {
            Gate::policy($model, WmsPolicy::class);
        }
    }
}
