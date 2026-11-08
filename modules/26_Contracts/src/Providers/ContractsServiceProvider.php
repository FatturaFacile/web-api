<?php

declare(strict_types=1);

namespace Modules\Contracts\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the routes and bindings of the Contracts module.
 */
final class ContractsServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
