<?php

declare(strict_types=1);

namespace Modules\SDI\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\SDI\Console\PollSdiChannelsCommand;
use Modules\SDI\Services\SdiGatewayManager;

final class SdiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SdiGatewayManager::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([PollSdiChannelsCommand::class]);
        }
    }
}
