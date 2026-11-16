<?php

declare(strict_types=1);

namespace Modules\Documents\Providers;

use Enterprise\AiGateway\Contracts\TenantAiConsentInterface;
use Illuminate\Support\ServiceProvider;
use Modules\Documents\Engines\PlainTextOcrEngine;
use Modules\Documents\Services\OcrEngineRegistry;
use Modules\Documents\Services\TenantAiConsent;

/**
 * Registers the routes and bindings of the Documents module.
 */
final class DocumentsServiceProvider extends ServiceProvider
{
    /**
     * Register the module bindings.
     */
    public function register(): void
    {
        $this->app->singleton(TenantAiConsentInterface::class, TenantAiConsent::class);

        // OCR engines: the built-in text engine first; an installation tags its own engines `dms.ocr_engines`.
        $this->app->tag([PlainTextOcrEngine::class], 'dms.ocr_engines');
        $this->app->bind(OcrEngineRegistry::class, fn ($app): OcrEngineRegistry => new OcrEngineRegistry($app->tagged('dms.ocr_engines')));
    }

    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
