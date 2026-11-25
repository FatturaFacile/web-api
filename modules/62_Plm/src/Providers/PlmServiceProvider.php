<?php

declare(strict_types=1);

namespace Modules\Plm\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Plm\Contracts\IPlmRegulatoryComplianceStrategy;
use Modules\Plm\Models\PlmAffectedBom;
use Modules\Plm\Models\PlmAffectedItem;
use Modules\Plm\Models\PlmCadImportSession;
use Modules\Plm\Models\PlmChangeApproval;
use Modules\Plm\Models\PlmChangeOrder;
use Modules\Plm\Models\PlmItemRevision;
use Modules\Plm\Models\PlmTechnicalDossier;
use Modules\Plm\Policies\PlmPolicy;
use Modules\Plm\Strategies\CeUkcaMachineryRoHsStrategy;
use Modules\Plm\Strategies\PlmRegulatoryComplianceStrategyFactory;

/**
 * Registers the routes, policies, and regulatory compliance strategies of the PLM module (Mod 168).
 */
final class PlmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(IPlmRegulatoryComplianceStrategy::class, CeUkcaMachineryRoHsStrategy::class);
        $this->app->singleton(PlmRegulatoryComplianceStrategyFactory::class, static fn (): PlmRegulatoryComplianceStrategyFactory => new PlmRegulatoryComplianceStrategyFactory([
            CeUkcaMachineryRoHsStrategy::CODE => new CeUkcaMachineryRoHsStrategy,
        ]));
    }

    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([
            PlmChangeOrder::class,
            PlmChangeApproval::class,
            PlmAffectedItem::class,
            PlmAffectedBom::class,
            PlmItemRevision::class,
            PlmCadImportSession::class,
            PlmTechnicalDossier::class,
        ] as $model) {
            Gate::policy($model, PlmPolicy::class);
        }
    }
}
