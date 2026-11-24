<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Actions;

use Modules\GeneralLedger\Contracts\PeriodClosingServiceInterface;
use Modules\GeneralLedger\Models\FiscalPeriod;

final readonly class CloseFiscalPeriodAction
{
    public function __construct(
        private PeriodClosingServiceInterface $closingService
    ) {}

    public function execute(string $fiscalPeriodId, string $closedBy): FiscalPeriod
    {
        return $this->closingService->closePeriod($fiscalPeriodId, $closedBy);
    }
}
