<?php

declare(strict_types=1);

namespace Modules\Communication\Providers;

use Illuminate\Support\ServiceProvider;

final class CommunicationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
