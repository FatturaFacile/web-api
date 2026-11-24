<?php

declare(strict_types=1);

namespace Modules\HealthExpenses\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the routes of the Tessera Sanitaria module.
 */
final class HealthExpensesServiceProvider extends ServiceProvider
{
    /**
     * Load the module routes.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
