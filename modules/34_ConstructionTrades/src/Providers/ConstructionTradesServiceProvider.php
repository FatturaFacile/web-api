<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Providers;

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

final class ConstructionTradesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'construction-trades');

        foreach ([PriceList::class, PriceItem::class, WorkCycle::class, WorkCycleStep::class, Bid::class, BidPosition::class, Measurement::class, Billing::class, SiteCost::class] as $model) {
            Gate::policy($model, TradesPolicy::class);
        }
    }
}
