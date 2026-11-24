<?php

declare(strict_types=1);

namespace Modules\SwissVat\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\SwissVat\Models\SwissVatReturn;
use Modules\SwissVat\Policies\SwissVatPolicy;

/**
 * Swiss VAT compliance module (Mod 024, Issue #164):
 * Effective & Saldosteuersatz reporting, Bezugsteuer detection, LIVA input tax adjustments,
 * AFC box reconciliation with quadratura checks, and SuisseTax eCH-0217 XML validation/export.
 */
final class SwissVatServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Gate::policy(SwissVatReturn::class, SwissVatPolicy::class);
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
