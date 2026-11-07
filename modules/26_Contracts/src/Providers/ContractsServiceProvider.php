<?php

declare(strict_types=1);

namespace Modules\Contracts\Providers;

use Illuminate\Support\ServiceProvider;

final class ContractsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
