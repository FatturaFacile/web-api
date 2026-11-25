<?php

declare(strict_types=1);

namespace Modules\Budgeting\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Budgeting\Console\ReconcileCommitmentsCommand;
use Modules\Budgeting\Contracts\LedgerLookup;
use Modules\Budgeting\Integration\ProcurementCommitmentSync;
use Modules\Budgeting\Integration\ProcurementSpendControl;
use Modules\Budgeting\Models\BudgetActualVolume;
use Modules\Budgeting\Models\BudgetApproval;
use Modules\Budgeting\Models\BudgetAuditEvent;
use Modules\Budgeting\Models\BudgetCommitment;
use Modules\Budgeting\Models\BudgetDriver;
use Modules\Budgeting\Models\BudgetImport;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetOverride;
use Modules\Budgeting\Models\BudgetSetting;
use Modules\Budgeting\Models\BudgetSpendCheck;
use Modules\Budgeting\Models\BudgetSpendRule;
use Modules\Budgeting\Models\BudgetVersion;
use Modules\Budgeting\Policies\BudgetingPolicy;
use Modules\Budgeting\Services\GeneralLedgerLookup;
use Modules\Procurement\Contracts\SpendControlInterface;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Events\PurchaseOrderApprovedEvent;
use Modules\Procurement\Events\PurchaseOrderCancelledEvent;
use Modules\Procurement\Events\PurchaseRequisitionApprovedEvent;

/**
 * Registers the routes, the policy, the ledger lookup and the spend lock of the Budgeting (planning, budgeting and
 * budget control) module. The spend lock replaces the pass-through control of purchasing and the commitments follow
 * the purchasing events.
 */
final class BudgetingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LedgerLookup::class, GeneralLedgerLookup::class);
        $this->app->bind(SpendControlInterface::class, ProcurementSpendControl::class);
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
            BudgetVersion::class, BudgetLine::class, BudgetDriver::class, BudgetApproval::class,
            BudgetSpendRule::class, BudgetCommitment::class, BudgetOverride::class, BudgetSpendCheck::class,
            BudgetSetting::class, BudgetActualVolume::class, BudgetImport::class, BudgetAuditEvent::class,
        ] as $model) {
            Gate::policy($model, BudgetingPolicy::class);
        }

        Event::listen(PurchaseRequisitionApprovedEvent::class, [ProcurementCommitmentSync::class, 'onRequisitionApproved']);
        Event::listen(PurchaseOrderApprovedEvent::class, [ProcurementCommitmentSync::class, 'onOrderApproved']);
        Event::listen(PurchaseOrderCancelledEvent::class, [ProcurementCommitmentSync::class, 'onOrderCancelled']);
        Event::listen(GoodsReceivedEvent::class, [ProcurementCommitmentSync::class, 'onGoodsReceived']);

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileCommitmentsCommand::class]);
        }
    }
}
