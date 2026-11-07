<?php

declare(strict_types=1);

namespace Modules\WorkspaceSync\Providers;

use Illuminate\Support\ServiceProvider;

final class WorkspaceSyncServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
