<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\ItalianPayroll\Infrastructure\Export\ExportAdapterRegistry;

/**
 * Italian payroll pre-processing and labour consultant export (Mod 077): routes and the stateless services.
 */
final class ItalianPayrollServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExportAdapterRegistry::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
