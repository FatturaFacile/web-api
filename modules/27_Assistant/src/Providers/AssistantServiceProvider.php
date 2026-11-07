<?php

declare(strict_types=1);

namespace Modules\Assistant\Providers;

use Illuminate\Support\ServiceProvider;

final class AssistantServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
