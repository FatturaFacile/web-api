<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Contracts;

use Modules\GeneralLedger\Models\ReconciliationRun;

interface AccountingReconciliationServiceInterface
{
    public function reconcileSubledger(string $tenantId, string $reconciliationType, string $fiscalPeriodId, ?string $actorUserId = null): ReconciliationRun;

    public function reconcileGrIr(string $tenantId, string $fiscalPeriodId, ?string $actorUserId = null): ReconciliationRun;
}
