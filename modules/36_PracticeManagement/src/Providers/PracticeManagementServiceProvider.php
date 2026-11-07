<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\PracticeManagement\Models\PeriodClosing;
use Modules\PracticeManagement\Policies\PracticePolicy;

final class PracticeManagementServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        Gate::policy(PeriodClosing::class, PracticePolicy::class);
    }
}
