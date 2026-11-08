<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Providers;

use App\Models\ConstructionSite;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\ConstructionTrades\Models\Bid;
use Modules\ConstructionTrades\Models\BidPosition;
use Modules\ConstructionTrades\Models\Billing;
use Modules\ConstructionTrades\Models\Measurement;
use Modules\ConstructionTrades\Models\PriceItem;
use Modules\ConstructionTrades\Models\PriceList;
use Modules\ConstructionTrades\Models\SiteCost;
use Modules\ConstructionTrades\Models\WorkCycle;
use Modules\ConstructionTrades\Models\WorkCycleStep;
use Modules\ConstructionTrades\Policies\TradesPolicy;

/**
 * Registers the construction trades module: routes, views and the policy of its models.
 */
final class ConstructionTradesServiceProvider extends ServiceProvider
{
    /**
     * Loads the module routes and views once the application is booted and registers the policy of every trades
     * model.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'construction-trades');

        foreach ([ConstructionSite::class, PriceList::class, PriceItem::class, WorkCycle::class, WorkCycleStep::class, Bid::class, BidPosition::class, Measurement::class, Billing::class, SiteCost::class] as $model) {
            Gate::policy($model, TradesPolicy::class);
        }
    }
}
