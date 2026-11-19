<?php

declare(strict_types=1);

namespace Modules\CorporateGroup\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\CorporateGroup\Http\Middleware\ResolveCompanyContext;
use Modules\CorporateGroup\Services\CompanySequenceService;
use Modules\CorporateGroup\Services\ConsolidationService;
use Modules\CorporateGroup\Services\IntercompanyService;

final class CorporateGroupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CompanySequenceService::class);
        $this->app->singleton(IntercompanyService::class);
        $this->app->singleton(ConsolidationService::class);
    }

    public function boot(): void
    {
        Route::aliasMiddleware('company.context', ResolveCompanyContext::class);
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
