<?php

declare(strict_types=1);

namespace Modules\Pricing\Providers;

use App\Models\PriceListItem;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Pricing\Models\CustomerSpecialPrice;
use Modules\Pricing\Models\MarginOverrideApproval;
use Modules\Pricing\Models\MarginPolicy;
use Modules\Pricing\Models\PriceListHistory;
use Modules\Pricing\Models\Promotion;
use Modules\Pricing\Observers\PriceChangeObserver;
use Modules\Pricing\Policies\PricingPolicy;
use Modules\Pricing\Services\PriceHistoryRecorder;

/**
 * Advanced pricing: routes, policy and the observers that keep the immutable price history.
 */
final class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PriceHistoryRecorder::class);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([CustomerSpecialPrice::class, Promotion::class, MarginPolicy::class, MarginOverrideApproval::class, PriceListHistory::class] as $model) {
            Gate::policy($model, PricingPolicy::class);
        }

        PriceListItem::observe(PriceChangeObserver::class);
        CustomerSpecialPrice::observe(PriceChangeObserver::class);
    }
}
