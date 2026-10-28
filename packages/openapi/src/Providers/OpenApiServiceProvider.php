<?php

declare(strict_types=1);

namespace Enterprise\OpenApi\Providers;

use Enterprise\OpenApi\Services\OpenApiRegistry;
use Illuminate\Support\ServiceProvider;

final class OpenApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OpenApiRegistry::class);
    }
}
