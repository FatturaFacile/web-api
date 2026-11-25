<?php

declare(strict_types=1);

namespace Modules\Returns\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Returns\Console\ScanOverdueAdvanceReplacementsCommand;
use Modules\Returns\Models\AdvanceReplacement;
use Modules\Returns\Models\CustomerReturn;
use Modules\Returns\Models\CustomerReturnLine;
use Modules\Returns\Models\RefurbishedItem;
use Modules\Returns\Models\ReturnGradingInspection;
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
 * Registers the routes, console commands, and the policy of the Returns and Warranty (RMA) module.
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

        if ($this->app->runningInConsole()) {
            $this->commands([
                ScanOverdueAdvanceReplacementsCommand::class,
            ]);
        }

        foreach ([
            ReturnsSetting::class,
            ReturnReasonCode::class,
            Rma::class,
            RmaLine::class,
            Warranty::class,
            WarrantyClaim::class,
            SupplierReturn::class,
            SupplierReturnLine::class,
            CustomerReturn::class,
            CustomerReturnLine::class,
            AdvanceReplacement::class,
            ReturnGradingInspection::class,
            RefurbishedItem::class,
        ] as $model) {
            Gate::policy($model, ReturnsPolicy::class);
        }
    }
}
