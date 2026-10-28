<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Actions;

use InvalidArgumentException;
use Modules\GeneralLedger\Contracts\FinancialStatementServiceInterface;

final readonly class GenerateFinancialStatementAction
{
    public function __construct(
        private FinancialStatementServiceInterface $statementService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(string $type, string $tenantId, string $fiscalPeriodId): array
    {
        return match (strtolower($type)) {
            'trial_balance', 'trial-balance' => $this->statementService->generateTrialBalance($tenantId, $fiscalPeriodId),
            'balance_sheet', 'balance-sheet' => $this->statementService->generateBalanceSheet($tenantId, $fiscalPeriodId),
            'income_statement', 'income-statement', 'pnl' => $this->statementService->generateIncomeStatement($tenantId, $fiscalPeriodId),
            'cash_flow', 'cash-flow' => $this->statementService->generateCashFlowPreparation($tenantId, $fiscalPeriodId),
            default => throw new InvalidArgumentException("Unsupported financial statement type [{$type}]."),
        };
    }
}
