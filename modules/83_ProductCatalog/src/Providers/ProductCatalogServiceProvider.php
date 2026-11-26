<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\ProductCatalog\Policies\CatalogPolicy;

/**
 * Mod 052 — product catalog: variants, kits, multi-unit packaging, CH / IT customs and tax classification, bulk import / export
 * (Issue #248).
 */
final class ProductCatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Gate::define(CatalogPolicy::ABILITY, [CatalogPolicy::class, 'access']);
    }
}
