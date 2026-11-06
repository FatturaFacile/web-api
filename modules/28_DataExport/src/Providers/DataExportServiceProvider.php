<?php

declare(strict_types=1);

namespace Modules\DataExport\Providers;

use Illuminate\Support\ServiceProvider;

final class DataExportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
