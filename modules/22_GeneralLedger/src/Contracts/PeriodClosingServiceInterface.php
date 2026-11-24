<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Contracts;

use Modules\GeneralLedger\Models\FinancialClosingRun;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\PeriodLock;

interface PeriodClosingServiceInterface
{
    public function closePeriod(string $fiscalPeriodId, string $closedBy): FiscalPeriod;

    public function reopenPeriod(string $fiscalPeriodId, string $openedBy): FiscalPeriod;

    public function lockSubledger(string $fiscalPeriodId, string $subledger, string $lockedBy): PeriodLock;

    public function closeFiscalYear(string $fiscalYearId, string $closedBy): FinancialClosingRun;

    public function reopenFiscalYear(string $fiscalYearId, string $actorId, string $reason): FiscalYear;

    public function approveFiscalYearAtAgm(string $fiscalYearId, string $actorId, ?string $resolutionReference = null): FiscalYear;

    /**
     * Posts the balances of a closed year as the opening entry of the next one. Repeating it returns the same entry.
     */
    public function carryForwardOpeningBalances(string $fiscalYearId, string $actorId): ?JournalEntry;
}
