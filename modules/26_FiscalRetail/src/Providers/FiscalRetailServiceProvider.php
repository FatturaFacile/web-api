<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Providers;

use Illuminate\Support\ServiceProvider;
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
    }
}
