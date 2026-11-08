<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the routes of the Document Trust module.
 */
final class DocumentTrustServiceProvider extends ServiceProvider
{
    /**
     * Load the module routes.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
