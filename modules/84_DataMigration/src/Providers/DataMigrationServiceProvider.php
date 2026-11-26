<?php

declare(strict_types=1);

namespace Modules\DataMigration\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\DataMigration\Policies\DataMigrationPolicy;

/**
 * Mod 104 — Guided Data Migration & Import/Export Wizard (Issue #253).
 */
final class DataMigrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Gate::define(DataMigrationPolicy::ABILITY_ACCESS, [DataMigrationPolicy::class, 'access']);
        Gate::define(DataMigrationPolicy::ABILITY_ROLLBACK, [DataMigrationPolicy::class, 'rollback']);
    }
}
