<?php

declare(strict_types=1);

namespace Modules\Communication\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the routes and bindings of the Communication module.
 */
final class CommunicationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
