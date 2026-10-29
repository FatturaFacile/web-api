<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Actions;

use Modules\GeneralLedger\Contracts\PeriodClosingServiceInterface;
use Modules\GeneralLedger\Models\FinancialClosingRun;

final readonly class CloseFiscalYearAction
{
    public function __construct(
        private PeriodClosingServiceInterface $closingService
    ) {}

    public function execute(string $fiscalYearId, string $closedBy): FinancialClosingRun
    {
        return $this->closingService->closeFiscalYear($fiscalYearId, $closedBy);
    }
}
