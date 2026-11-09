<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\PracticeManagement\Models\PeriodClosing;
use Modules\PracticeManagement\Models\Retainer;
use Modules\PracticeManagement\Policies\PracticePolicy;

/**
 * Registers the practice management module: routes and the closing policy.
 */
final class PracticeManagementServiceProvider extends ServiceProvider
{
    /**
     * Loads the module routes once the application is booted and registers the closing policy.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        Gate::policy(PeriodClosing::class, PracticePolicy::class);
        Gate::policy(Retainer::class, PracticePolicy::class);
    }
}
