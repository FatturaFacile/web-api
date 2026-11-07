<?php

declare(strict_types=1);

namespace Modules\HealthExpenses\Providers;

use Illuminate\Support\ServiceProvider;

final class HealthExpensesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
