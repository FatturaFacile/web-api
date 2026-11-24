<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\ItalianVat\Contracts\LipeSignatureVerifier;
use Modules\ItalianVat\Services\OpenSslLipeSignatureVerifier;

/**
 * Italian VAT compliance (Mod 029): VAT registers with sectionals and definitive print, plafond of the habitual exporters,
 * quarterly LIPE, annual VAT reconciliation and Certificazione Unica. Loads the routes and the PDF views.
 */
final class ItalianVatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LipeSignatureVerifier::class, OpenSslLipeSignatureVerifier::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'italian-vat');
    }
}
