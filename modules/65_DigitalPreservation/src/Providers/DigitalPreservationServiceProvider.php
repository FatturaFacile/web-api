<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\DigitalPreservation\Infrastructure\Console\PreservationRunCommand;
use Modules\DigitalPreservation\Infrastructure\Console\PreservationVerifyCommand;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatchItem;
use Modules\DigitalPreservation\Presentation\Policies\PreservationPolicy;

/**
 * Registers the routes, the policy and the commands of the digital preservation module (AgID conservazione, GeBüV/Olico).
 */
final class DigitalPreservationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(PreservationBatch::class, PreservationPolicy::class);
        Gate::policy(PreservationBatchItem::class, PreservationPolicy::class);
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([PreservationRunCommand::class, PreservationVerifyCommand::class]);
        }
    }
}
