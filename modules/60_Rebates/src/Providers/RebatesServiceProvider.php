<?php

declare(strict_types=1);

namespace Modules\Rebates\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Rebates\Models\RebateAccrual;
use Modules\Rebates\Models\RebateAgreement;
use Modules\Rebates\Models\RebatePromotion;
use Modules\Rebates\Models\RebateSettlement;
use Modules\Rebates\Models\RebateTier;
use Modules\Rebates\Policies\RebatesPolicy;

/**
 * Registers the routes and the policy of the Rebates (volume rebates and trade promotions) module.
 */
final class RebatesServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([RebateAgreement::class, RebateTier::class, RebateAccrual::class, RebateSettlement::class, RebatePromotion::class] as $model) {
            Gate::policy($model, RebatesPolicy::class);
        }
    }
}
