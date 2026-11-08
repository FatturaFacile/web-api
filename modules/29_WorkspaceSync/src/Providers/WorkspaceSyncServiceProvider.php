<?php

declare(strict_types=1);

namespace Modules\WorkspaceSync\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the routes and bindings of the WorkspaceSync module.
 */
final class WorkspaceSyncServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
