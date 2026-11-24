<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Listeners;

use Modules\GeneralLedger\Services\PayrollLedgerService;
use Modules\Payroll\Events\PayrollRunFinalizedEvent;
use Modules\Payroll\Events\PayrollRunReopenedEvent;

final readonly class PayrollAccountingListener
{
    public function __construct(
        private PayrollLedgerService $ledger
    ) {}

    public function handleFinalized(PayrollRunFinalizedEvent $event): void
    {
        $this->ledger->recordFinalized($event);
    }

    public function handleReopened(PayrollRunReopenedEvent $event): void
    {
        $this->ledger->recordReopened($event->payrollRunId, $event->tenantId, $event->reason);
    }
}
