<?php

declare(strict_types=1);

namespace Modules\Treasury\Actions;

use Modules\Treasury\Contracts\BankReconciliationServiceInterface;
use Modules\Treasury\Models\BankReconciliation;

final readonly class ReconcileBankStatementAction
{
    public function __construct(
        private BankReconciliationServiceInterface $reconciliationService
    ) {}

    public function execute(string $bankStatementId, ?string $userId = null): BankReconciliation
    {
        return $this->reconciliationService->runAutoReconciliation($bankStatementId, $userId);
    }
}
