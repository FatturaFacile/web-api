<?php

declare(strict_types=1);

namespace Modules\Returns\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Returns\Models\ReturnReasonCode;
use Modules\Returns\Models\ReturnsSetting;
use Modules\Returns\Models\Rma;
use Modules\Returns\Models\RmaLine;
use Modules\Returns\Models\SupplierReturn;
use Modules\Returns\Models\SupplierReturnLine;
use Modules\Returns\Models\Warranty;
use Modules\Returns\Models\WarrantyClaim;
use Modules\Returns\Policies\ReturnsPolicy;

/**
 * Registers the routes and the policy of the Returns and Warranty (RMA) module.
 */
final class ReturnsServiceProvider extends ServiceProvider
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
            ReturnsSetting::class,
            ReturnReasonCode::class,
            Rma::class,
            RmaLine::class,
            Warranty::class,
            WarrantyClaim::class,
            SupplierReturn::class,
            SupplierReturnLine::class,
        ] as $model) {
            Gate::policy($model, ReturnsPolicy::class);
        }
    }
}
