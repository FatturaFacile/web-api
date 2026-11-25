<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\StatutoryFinancials\Adapters\AssetManagementFixedAssetRegister;
use Modules\StatutoryFinancials\Adapters\HumanResourcesWorkforceRegister;
use Modules\StatutoryFinancials\Adapters\LeaseAccountingLeaseRegister;
use Modules\StatutoryFinancials\Adapters\LedgerCompensationRegister;
use Modules\StatutoryFinancials\Adapters\ScheduleDueDateSchedule;
use Modules\StatutoryFinancials\Contracts\CompensationRegisterPort;
use Modules\StatutoryFinancials\Contracts\DueDateSchedulePort;
use Modules\StatutoryFinancials\Contracts\FixedAssetRegisterPort;
use Modules\StatutoryFinancials\Contracts\IStatutoryReportingComplianceStrategy;
use Modules\StatutoryFinancials\Contracts\LeaseRegisterPort;
use Modules\StatutoryFinancials\Contracts\WorkforceRegisterPort;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Pdf\PdfADocumentFactory;
use Modules\StatutoryFinancials\Policies\StatutoryFinancialsPolicy;
use Modules\StatutoryFinancials\Strategies\ChCoArt959SwissGaapFerAnhangStrategy;
use Modules\StatutoryFinancials\Strategies\ItXbrlOic10CrisiImpresaStrategy;
use Modules\StatutoryFinancials\Strategies\StatutoryStrategyResolver;

/**
 * Mod 167 — Statutory financial statement dossier: Italian XBRL civil-code financial statements (OIC) and Swiss CO Art. 959 /
 * Swiss GAAP FER annual accounts with notes, crisis indicators and the PDF/A deposit package (Issue #413).
 */
final class StatutoryFinancialsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FixedAssetRegisterPort::class, AssetManagementFixedAssetRegister::class);
        $this->app->bind(DueDateSchedulePort::class, ScheduleDueDateSchedule::class);
        $this->app->bind(LeaseRegisterPort::class, LeaseAccountingLeaseRegister::class);
        $this->app->bind(WorkforceRegisterPort::class, HumanResourcesWorkforceRegister::class);
        $this->app->bind(CompensationRegisterPort::class, LedgerCompensationRegister::class);

        $this->app->singleton(PdfADocumentFactory::class);
        $this->app->tag([ItXbrlOic10CrisiImpresaStrategy::class, ChCoArt959SwissGaapFerAnhangStrategy::class], IStatutoryReportingComplianceStrategy::class);
        $this->app->bind(StatutoryStrategyResolver::class, fn ($app): StatutoryStrategyResolver => new StatutoryStrategyResolver($app->tagged(IStatutoryReportingComplianceStrategy::class)));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Gate::policy(StatutoryDossier::class, StatutoryFinancialsPolicy::class);
    }
}
