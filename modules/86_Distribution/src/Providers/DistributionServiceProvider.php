<?php

declare(strict_types=1);

namespace Modules\Distribution\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Distribution\Console\RunDrpCommand;
use Modules\Distribution\Models\ConsignmentSettlement;
use Modules\Distribution\Models\DistributionLane;
use Modules\Distribution\Models\DrpAlert;
use Modules\Distribution\Models\DrpRun;
use Modules\Distribution\Models\ReplenishmentProposal;
use Modules\Distribution\Models\ReplenishmentRule;
use Modules\Distribution\Models\SalesChannel;
use Modules\Distribution\Models\SalesChannelWarehouse;
use Modules\Distribution\Models\ThreePlReconciliation;
use Modules\Distribution\Models\TransferMeta;
use Modules\Distribution\Models\WarehouseProfile;
use Modules\Distribution\Policies\DistributionPolicy;

/**
 * Distribution, multi-warehouse and transfers: DRP (Issue #271).
 */
final class DistributionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([
            WarehouseProfile::class, DistributionLane::class, TransferMeta::class, ReplenishmentRule::class, ReplenishmentProposal::class,
            DrpRun::class, DrpAlert::class, SalesChannel::class, SalesChannelWarehouse::class, ConsignmentSettlement::class, ThreePlReconciliation::class,
        ] as $model) {
            Gate::policy($model, DistributionPolicy::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([RunDrpCommand::class]);
        }
    }
}
