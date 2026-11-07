<?php

declare(strict_types=1);

namespace Modules\EDocumentNetworks\Providers;

use Illuminate\Support\ServiceProvider;

final class EDocumentNetworksServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
