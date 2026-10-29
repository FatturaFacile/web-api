<?php

declare(strict_types=1);

namespace Enterprise\MultiTenant\Providers;

use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\ServiceProvider;

final class MultiTenantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->bound(TenantContext::class)) {
            $this->app->singleton(TenantContext::class, fn (): TenantContext => new TenantContext);
        }

        $this->app->singleton(TenantScope::class);
    }
}
