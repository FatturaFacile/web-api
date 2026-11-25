<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\DebtCollection\Console\PollPecReceiptsCommand;
use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Policies\DebtCollectionPolicy;
use Modules\DebtCollection\Services\InterestRateResolver;
use Modules\DebtCollection\Services\PolicyResolver;

/**
 * Mod 014 - Dunning and debt collection (Mahnwesen), Issue #267.
 */
final class DebtCollectionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // These cache what they read; a scoped binding gives every request and every queued job a fresh cache.
        $this->app->scoped(PolicyResolver::class);
        $this->app->scoped(InterestRateResolver::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        // Every ability is checked against the notice class; one policy answers read, manage and escalate.
        Gate::policy(Notice::class, DebtCollectionPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PollPecReceiptsCommand::class]);
        }
    }
}
