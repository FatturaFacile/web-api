<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\FiduciaryPortal\Policies\FiduciaryPortalPolicy;

/**
 * Mod 032 — External Accountant & Fiduciary Portal (Issue #336).
 */
final class FiduciaryPortalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Gate::define(FiduciaryPortalPolicy::ABILITY_VIEW, [FiduciaryPortalPolicy::class, 'view']);
        Gate::define(FiduciaryPortalPolicy::ABILITY_VERIFY, [FiduciaryPortalPolicy::class, 'verify']);
        Gate::define(FiduciaryPortalPolicy::ABILITY_TRANSCODE, [FiduciaryPortalPolicy::class, 'transcode']);
        Gate::define(FiduciaryPortalPolicy::ABILITY_EXPORT, [FiduciaryPortalPolicy::class, 'export']);
        Gate::define(FiduciaryPortalPolicy::ABILITY_LOCK_PERIOD, [FiduciaryPortalPolicy::class, 'lockPeriod']);
        Gate::define(FiduciaryPortalPolicy::ABILITY_NOTES, [FiduciaryPortalPolicy::class, 'notes']);
        Gate::define(FiduciaryPortalPolicy::ABILITY_DOSSIER, [FiduciaryPortalPolicy::class, 'dossier']);
    }
}
