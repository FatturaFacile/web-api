<?php

declare(strict_types=1);

namespace Enterprise\FeatureFlags\Providers;

use Enterprise\FeatureFlags\Contracts\FeatureManagerInterface;
use Enterprise\FeatureFlags\Services\FeatureManager;
use Illuminate\Support\ServiceProvider;

final class FeatureFlagsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FeatureManagerInterface::class, FeatureManager::class);
        $this->app->singleton(FeatureManager::class);
    }
}
