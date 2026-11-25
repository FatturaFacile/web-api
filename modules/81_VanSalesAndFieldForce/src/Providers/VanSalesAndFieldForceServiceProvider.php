<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\VanSalesAndFieldForce\Models\FieldRoutePlan;
use Modules\VanSalesAndFieldForce\Models\VanEodSettlement;
use Modules\VanSalesAndFieldForce\Models\VanLoadManifest;
use Modules\VanSalesAndFieldForce\Models\VanVisitOutcome;
use Modules\VanSalesAndFieldForce\Models\VanWarehouse;
use Modules\VanSalesAndFieldForce\Policies\VanSalesPolicy;

/**
 * Mod 233 — Van Selling (Tentata Vendita su Furgone) & Offline Field Sales (Issue #479).
 */
final class VanSalesAndFieldForceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([
            VanWarehouse::class,
            VanLoadManifest::class,
            FieldRoutePlan::class,
            VanVisitOutcome::class,
            VanEodSettlement::class,
        ] as $model) {
            Gate::policy($model, VanSalesPolicy::class);
        }
    }
}
