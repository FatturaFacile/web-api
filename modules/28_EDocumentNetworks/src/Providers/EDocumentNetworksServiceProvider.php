<?php

declare(strict_types=1);

namespace Modules\EDocumentNetworks\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the routes and bindings of the EDocumentNetworks module.
 */
final class EDocumentNetworksServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
