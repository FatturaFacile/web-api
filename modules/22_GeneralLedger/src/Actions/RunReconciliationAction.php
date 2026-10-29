<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Actions;

use Modules\GeneralLedger\Contracts\AccountingReconciliationServiceInterface;
use Modules\GeneralLedger\Models\ReconciliationRun;

final readonly class RunReconciliationAction
{
    public function __construct(
        private AccountingReconciliationServiceInterface $reconciliationService
    ) {}

    public function execute(string $tenantId, string $reconciliationType, string $fiscalPeriodId, ?string $actorUserId = null): ReconciliationRun
    {
        if ($reconciliationType === 'gr_ir') {
            return $this->reconciliationService->reconcileGrIr($tenantId, $fiscalPeriodId, $actorUserId);
        }

        return $this->reconciliationService->reconcileSubledger($tenantId, $reconciliationType, $fiscalPeriodId, $actorUserId);
    }
}
