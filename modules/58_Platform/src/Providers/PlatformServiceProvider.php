<?php

declare(strict_types=1);

namespace Modules\Platform\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Platform\Console\PruneIdempotencyKeysCommand;

/**
 * Cross-cutting platform features: idempotent mutating requests, ETag / If-Match optimistic locking (both middleware
 * are part of the `tenant` group, see bootstrap/app.php), the recycle bin and the in-app notification centre.
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        if ($this->app->runningInConsole()) {
            $this->commands([PruneIdempotencyKeysCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(PruneIdempotencyKeysCommand::class)->hourly()->withoutOverlapping()->onOneServer();
        });
    }
}
