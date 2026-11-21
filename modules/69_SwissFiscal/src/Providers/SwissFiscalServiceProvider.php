<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\SwissFiscal\Infrastructure\FatturaPa\ItalianFatturaPaGenerator;
use Modules\SwissFiscal\Infrastructure\FiscalDocumentGeneratorRegistry;
use Modules\SwissFiscal\Infrastructure\QrBill\SwissQrBillGenerator;

/** Swiss and Italian fiscal document strategies, QR-bill and ISO 20022 endpoints (Issue #83). */
final class SwissFiscalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FiscalDocumentGeneratorRegistry::class, fn ($app): FiscalDocumentGeneratorRegistry => new FiscalDocumentGeneratorRegistry([
            $app->make(SwissQrBillGenerator::class),
            $app->make(ItalianFatturaPaGenerator::class),
        ]));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
