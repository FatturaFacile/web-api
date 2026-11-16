<?php

declare(strict_types=1);

namespace Modules\Logistics\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Logistics\Console\RunDeferredInvoicingCommand;
use Modules\Logistics\Http\Middleware\EnsureLogisticsPermission;
use Modules\Logistics\Http\Middleware\WrapApiEnvelope;
use Modules\Logistics\Services\ConsignmentService;
use Modules\Logistics\Services\DeferredInvoicingService;
use Modules\Logistics\Services\DeliveryNoteNumberingService;
use Modules\Logistics\Services\DeliveryNoteService;
use Modules\Logistics\Services\ShippingCarrierManager;
use Modules\Logistics\Services\StockTransferService;
use Modules\Logistics\Services\WarehouseStockService;

final class LogisticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ShippingCarrierManager::class);
        $this->app->singleton(WarehouseStockService::class);
        $this->app->singleton(DeliveryNoteNumberingService::class);
        $this->app->singleton(DeliveryNoteService::class);
        $this->app->singleton(DeferredInvoicingService::class);
        $this->app->singleton(StockTransferService::class);
        $this->app->singleton(ConsignmentService::class);
    }

    public function boot(): void
    {
        Route::aliasMiddleware('logistics.can', EnsureLogisticsPermission::class);
        Route::aliasMiddleware('logistics.envelope', WrapApiEnvelope::class);
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([RunDeferredInvoicingCommand::class]);
        }
    }
}
