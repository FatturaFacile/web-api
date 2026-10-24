<?php

namespace App\Providers;

use App\Contracts\FiscalPrinterGateway;
use App\Contracts\PdfGenerator;
use App\Contracts\SdiProvider;
use App\Services\Dashboard\WidgetRegistry;
use App\Services\Ecommerce\EcommerceManager;
use App\Services\ItalianSdi\LocalSdiProvider;
use App\Services\Pdf\DompdfPdfGenerator;
use App\Services\Retail\LocalFiscalPrinterGateway;
use App\Services\Tenancy\TenantContext;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, fn () => new TenantContext);
        $this->app->bind(SdiProvider::class, LocalSdiProvider::class);
        $this->app->bind(FiscalPrinterGateway::class, LocalFiscalPrinterGateway::class);
        $this->app->bind(PdfGenerator::class, DompdfPdfGenerator::class);
        $this->app->singleton(EcommerceManager::class);
        $this->app->singleton(WidgetRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Scramble::afterOpenApiGenerated(function ($openApi) {});

        Gate::define('viewApiDocs', function ($user) {
            return in_array($user->email, ['tuamela@example.com']);
        });
    }
}
