<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\AnalyticsDss\Infrastructure\Projection\MartProjector;
use Modules\AnalyticsDss\Infrastructure\Projection\MartReconciler;

/**
 * Rebuilds the analytical data mart of one or every tenant from the operational tables and, with --reconcile, proves it
 * against the General Ledger checksum. The exit code is non-zero when any account shows a variance.
 */
final class ConsolidateMartsCommand extends Command
{
    protected $signature = 'bi:consolidate-marts {--reconcile : Verify the mart against the General Ledger and fail on any variance} {--tenant= : Only this tenant id}';

    protected $description = 'Rebuild the executive DSS data marts and reconcile them with the General Ledger';

    public function handle(MartProjector $projector, MartReconciler $reconciler): int
    {
        $tenantIds = DB::table('tenants')->whereNull('deleted_at')
            ->when($this->option('tenant'), fn ($q, $id) => $q->where('id', (string) $id))
            ->orderBy('id')->pluck('id');

        if ($tenantIds->isEmpty()) {
            $this->warn('No tenant to consolidate.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($tenantIds as $tenantId) {
            $documents = $projector->rebuildTenant((string) $tenantId);
            $this->line("Tenant {$tenantId}: {$documents} source documents projected.");

            if (! $this->option('reconcile')) {
                continue;
            }

            $result = $reconciler->reconcile((string) $tenantId);
            $this->line(sprintf('  Reconciliation: %d accounts, variance %.2f, snapshot variance %.2f', $result['accounts_checked'], $result['variance'], $result['snapshot_variance']));

            foreach ($result['mismatches'] as $mismatch) {
                $this->error(sprintf('  Account %s: ledger %.2f, mart %.2f (%.2f)', $mismatch['account_id'], $mismatch['ledger'], $mismatch['mart'], $mismatch['variance']));
            }

            $failed = $failed || $result['variance'] !== 0.0 || $result['snapshot_variance'] !== 0.0;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
