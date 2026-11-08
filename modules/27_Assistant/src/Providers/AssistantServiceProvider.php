<?php

declare(strict_types=1);

namespace Modules\Assistant\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the routes and bindings of the Assistant module.
 */
final class AssistantServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
