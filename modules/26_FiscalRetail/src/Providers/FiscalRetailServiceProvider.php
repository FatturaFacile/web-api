<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Providers;

use App\Models\PosSale;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\FiscalRetail\Models\RetailCashMovement;
use Modules\FiscalRetail\Models\RetailPromotion;
use Modules\FiscalRetail\Models\RetailReturn;
use Modules\FiscalRetail\Models\RetailSessionReport;
use Modules\FiscalRetail\Policies\RetailPolicy;
use Modules\FiscalRetail\Services\CorrispettiviGatewayManager;

final class FiscalRetailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CorrispettiviGatewayManager::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([PosSale::class, RetailPromotion::class, RetailCashMovement::class, RetailReturn::class, RetailSessionReport::class] as $model) {
            Gate::policy($model, RetailPolicy::class);
        }
    }
}
