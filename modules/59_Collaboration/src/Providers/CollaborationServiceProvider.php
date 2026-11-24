<?php

declare(strict_types=1);

namespace Modules\Collaboration\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Collaboration\Models\CollabComment;
use Modules\Collaboration\Models\CollabTag;
use Modules\Collaboration\Policies\CollaborationPolicy;
use Modules\Collaboration\Services\CollaborationRegistry;
use Modules\Collaboration\Services\DefaultRecordTypes;

/**
 * Registers the routes, the policy and the record type registry of the collaboration module (global search, comments with
 * mentions, shared tags, record activity).
 */
final class CollaborationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CollaborationRegistry::class);
    }

    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        DefaultRecordTypes::registerInto($this->app->make(CollaborationRegistry::class));

        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([CollabComment::class, CollabTag::class] as $model) {
            Gate::policy($model, CollaborationPolicy::class);
        }
    }
}
