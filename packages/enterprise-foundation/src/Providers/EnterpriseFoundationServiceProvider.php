<?php

declare(strict_types=1);

namespace Enterprise\Foundation\Providers;

use Illuminate\Support\ServiceProvider;

final class EnterpriseFoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind core ERP Foundation configuration and services
        $this->mergeConfigFrom(
            __DIR__.'/../../config/foundation.php',
            'erp_foundation'
        );
    }

    public function boot(): void
    {
        // Enforce enterprise model constraints and standard behaviors
    }
}
