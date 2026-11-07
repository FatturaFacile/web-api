<?php

declare(strict_types=1);

namespace Modules\Documents\Providers;

use Enterprise\AiGateway\Contracts\TenantAiConsentInterface;
use Illuminate\Support\ServiceProvider;
use Modules\Documents\Services\TenantAiConsent;

final class DocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantAiConsentInterface::class, TenantAiConsent::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
