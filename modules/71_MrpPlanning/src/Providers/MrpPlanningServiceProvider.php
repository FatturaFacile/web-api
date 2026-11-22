<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\MrpPlanning\Domain\Contracts\MasterScheduleGatewayInterface;
use Modules\MrpPlanning\Domain\Contracts\MrpSnapshotLoaderInterface;
use Modules\MrpPlanning\Domain\Contracts\ReleaseGatewayInterface;
use Modules\MrpPlanning\Infrastructure\Services\EloquentMrpSnapshotLoader;
use Modules\MrpPlanning\Infrastructure\Services\MasterScheduleGateway;
use Modules\MrpPlanning\Infrastructure\Services\ReleaseGateway;

/**
 * MRP I & II (Mod 063): binds the ports towards the upstream modules (snapshot loader, master production schedule,
 * release into procurement and production) and loads the routes.
 */
final class MrpPlanningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MrpSnapshotLoaderInterface::class, EloquentMrpSnapshotLoader::class);
        $this->app->bind(MasterScheduleGatewayInterface::class, MasterScheduleGateway::class);
        $this->app->bind(ReleaseGatewayInterface::class, ReleaseGateway::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
