<?php

declare(strict_types=1);

namespace Modules\Treasury\Actions;

use Modules\Treasury\Contracts\BankReconciliationServiceInterface;
use Modules\Treasury\Models\BankStatement;

final readonly class ImportBankStatementAction
{
    public function __construct(
        private BankReconciliationServiceInterface $reconciliationService
    ) {}

    /**
     * @param  array<string, mixed>  $statementData
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function execute(array $statementData, array $lines): BankStatement
    {
        return $this->reconciliationService->importStatement($statementData, $lines);
    }
}
