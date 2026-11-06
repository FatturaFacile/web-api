<?php

declare(strict_types=1);

namespace Modules\Integrations\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Modules\Integrations\Http\Middleware\AuthenticateApiKey;

final class IntegrationsServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        $router->aliasMiddleware('api.key', AuthenticateApiKey::class);

        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
