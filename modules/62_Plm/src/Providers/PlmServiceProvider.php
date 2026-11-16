<?php

declare(strict_types=1);

namespace Modules\Plm\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Plm\Models\PlmAffectedBom;
use Modules\Plm\Models\PlmAffectedItem;
use Modules\Plm\Models\PlmChangeApproval;
use Modules\Plm\Models\PlmChangeOrder;
use Modules\Plm\Models\PlmItemRevision;
use Modules\Plm\Policies\PlmPolicy;

/**
 * Registers the routes and the policy of the PLM (product lifecycle management) module.
 */
final class PlmServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([PlmChangeOrder::class, PlmChangeApproval::class, PlmAffectedItem::class, PlmAffectedBom::class, PlmItemRevision::class] as $model) {
            Gate::policy($model, PlmPolicy::class);
        }
    }
}
