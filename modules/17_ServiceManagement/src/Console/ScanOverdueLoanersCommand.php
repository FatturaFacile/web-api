<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Console;

use Illuminate\Console\Command;
use Modules\ServiceManagement\Jobs\ScanOverdueLoanersJob;
use Modules\ServiceManagement\Services\LoanerService;

final class ScanOverdueLoanersCommand extends Command
{
    protected $signature = 'esm:loaners:scan-overdue
                            {--tenant= : Limit the scan to one tenant UUID}
                            {--grace-days= : Override service-management.rma.loaner_grace_days}
                            {--queue : Dispatch ScanOverdueLoanersJob to the queue instead of running inline}';

    protected $description = 'Raise one LoanerOverdueEvent per loaner unit still out after the repaired equipment was returned (idempotent)';

    public function handle(LoanerService $loanerService): int
    {
        $tenantId = $this->option('tenant') !== null ? (string) $this->option('tenant') : null;
        $graceDays = $this->option('grace-days') !== null ? (int) $this->option('grace-days') : null;

        if ($this->option('queue')) {
            ScanOverdueLoanersJob::dispatch($tenantId, $graceDays);
            $this->info('Overdue loaner scan queued.');

            return self::SUCCESS;
        }

        $alerted = $loanerService->scanOverdue($tenantId, $graceDays);
        $this->info("Overdue loaner scan completed: {$alerted} new alert(s).");

        return self::SUCCESS;
    }
}
