<?php

declare(strict_types=1);

namespace Modules\Analytics\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the routes and bindings of the Analytics module.
 */
final class AnalyticsServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
