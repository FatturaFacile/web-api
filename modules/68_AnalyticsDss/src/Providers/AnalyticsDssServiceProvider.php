<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\AnalyticsDss\Infrastructure\Console\ConsolidateMartsCommand;
use Modules\AnalyticsDss\Infrastructure\Listeners\AnalyticalMartEventSubscriber;
use Modules\AnalyticsDss\Infrastructure\Support\DssCache;

/**
 * Executive DSS and analytical data mart (Mod 093, Issue #214): routes, the incremental mart projectors fed by the
 * operational events, and the `bi:consolidate-marts` command.
 */
final class AnalyticsDssServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/analytics_dss.php', 'analytics_dss');

        // One cache helper per request; the projector and the dimension writer stay per-resolution so a queue worker never keeps a stale "seen" memo.
        $this->app->singleton(DssCache::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Event::subscribe(AnalyticalMartEventSubscriber::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ConsolidateMartsCommand::class]);
        }
    }
}
