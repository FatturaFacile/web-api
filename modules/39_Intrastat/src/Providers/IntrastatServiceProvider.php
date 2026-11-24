<?php

declare(strict_types=1);

namespace Modules\Intrastat\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Intrastat\Models\IntrastatDeclaration;
use Modules\Intrastat\Models\IntrastatDeclarationLine;
use Modules\Intrastat\Models\IntrastatProductProfile;
use Modules\Intrastat\Policies\IntrastatPolicy;

final class IntrastatServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([IntrastatDeclaration::class, IntrastatDeclarationLine::class, IntrastatProductProfile::class] as $model) {
            Gate::policy($model, IntrastatPolicy::class);
        }
    }
}
