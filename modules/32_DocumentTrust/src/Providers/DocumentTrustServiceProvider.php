<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Providers;

use Illuminate\Support\ServiceProvider;

final class DocumentTrustServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
