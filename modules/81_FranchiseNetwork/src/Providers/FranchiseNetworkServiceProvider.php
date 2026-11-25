<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\FranchiseNetwork\Console\RunFranchiseNetworkCommand;
use Modules\FranchiseNetwork\Contracts\CatalogSyncGatewayInterface;
use Modules\FranchiseNetwork\Models\AuditAction;
use Modules\FranchiseNetwork\Models\AuditTemplate;
use Modules\FranchiseNetwork\Models\BenchmarkNetworkStat;
use Modules\FranchiseNetwork\Models\CatalogPush;
use Modules\FranchiseNetwork\Models\FranchiseAgreement;
use Modules\FranchiseNetwork\Models\FranchiseFormula;
use Modules\FranchiseNetwork\Models\FranchiseStore;
use Modules\FranchiseNetwork\Models\LoyaltyTransaction;
use Modules\FranchiseNetwork\Models\PurchaseRecord;
use Modules\FranchiseNetwork\Models\RoyaltyRun;
use Modules\FranchiseNetwork\Models\SellOutReport;
use Modules\FranchiseNetwork\Models\StoreAudit;
use Modules\FranchiseNetwork\Policies\FranchiseNetworkPolicy;
use Modules\FranchiseNetwork\Services\LocalCatalogSyncGateway;

/**
 * Mod 244 — franchise network governance: franchisor / franchisee hierarchy, agreements, royalty billing, central pushes, audits
 * and the anonymous benchmark (Issue #490).
 */
final class FranchiseNetworkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CatalogSyncGatewayInterface::class, LocalCatalogSyncGateway::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([FranchiseStore::class, FranchiseFormula::class, FranchiseAgreement::class, CatalogPush::class, SellOutReport::class, PurchaseRecord::class, LoyaltyTransaction::class, RoyaltyRun::class, StoreAudit::class, AuditAction::class, AuditTemplate::class, BenchmarkNetworkStat::class] as $model) {
            Gate::policy($model, FranchiseNetworkPolicy::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([RunFranchiseNetworkCommand::class]);
        }
    }
}
