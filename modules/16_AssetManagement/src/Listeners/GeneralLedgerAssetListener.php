<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\AssetManagement\Models\DepreciationRun;
use Modules\GeneralLedger\Events\JournalPostedEvent;
use Modules\GeneralLedger\Events\PeriodClosedEvent;
use Modules\GeneralLedger\Events\YearClosedEvent;

/**
 * Reacts to the closing of General Ledger periods. Depreciation is manual by design: closing a period no longer starts
 * a run (a run after the close would change the asset books without any possible posting), it only logs a warning
 * when the year is closed without any statutory depreciation run, so the accountant can reopen and depreciate.
 */
final readonly class GeneralLedgerAssetListener
{
    /**
     * Journal entries of the module are linked synchronously when posted; nothing to do on replay.
     */
    public function handleJournalPosted(JournalPostedEvent $event): void {}

    /**
     * Period closing does not depreciate: runs are started by the accountant before closing.
     */
    public function handlePeriodClosed(PeriodClosedEvent $event): void {}

    /**
     * Warns when a year is closed without any statutory depreciation run.
     */
    public function handleYearClosed(YearClosedEvent $event): void
    {
        $year = preg_replace('/\D/', '', $event->yearCode) ?: $event->yearCode;

        $hasRun = DepreciationRun::withoutGlobalScopes()
            ->where('tenant_id', $event->tenantId)
            ->where('ledger_type', 'statutory')
            ->where('period_code', 'like', $year.'%')
            ->whereNull('deleted_at')
            ->exists();

        if (! $hasRun) {
            Log::warning('Fiscal year closed without statutory depreciation runs.', ['tenant_id' => $event->tenantId, 'year_code' => $event->yearCode]);
        }
    }
}
