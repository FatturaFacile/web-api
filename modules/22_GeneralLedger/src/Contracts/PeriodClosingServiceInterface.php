<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Contracts;

use Modules\GeneralLedger\Models\FinancialClosingRun;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\PeriodLock;

interface PeriodClosingServiceInterface
{
    public function closePeriod(string $fiscalPeriodId, string $closedBy): FiscalPeriod;

    public function reopenPeriod(string $fiscalPeriodId, string $openedBy): FiscalPeriod;

    public function lockSubledger(string $fiscalPeriodId, string $subledger, string $lockedBy): PeriodLock;

    public function closeFiscalYear(string $fiscalYearId, string $closedBy): FinancialClosingRun;
}
