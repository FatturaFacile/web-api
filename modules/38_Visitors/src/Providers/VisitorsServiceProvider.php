<?php

declare(strict_types=1);

namespace Modules\Visitors\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Visitors\Models\Visitor;
use Modules\Visitors\Models\VisitorKiosk;
use Modules\Visitors\Models\VisitorVisit;
use Modules\Visitors\Policies\VisitorsPolicy;

final class VisitorsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'visitors');

        foreach ([Visitor::class, VisitorVisit::class, VisitorKiosk::class] as $model) {
            Gate::policy($model, VisitorsPolicy::class);
        }
    }
}
