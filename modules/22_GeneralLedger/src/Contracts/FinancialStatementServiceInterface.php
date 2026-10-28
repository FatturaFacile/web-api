<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Contracts;

interface FinancialStatementServiceInterface
{
    /**
     * @return array<string, mixed>
     */
    public function generateTrialBalance(string $tenantId, string $fiscalPeriodId): array;

    /**
     * @return array<string, mixed>
     */
    public function generateBalanceSheet(string $tenantId, string $fiscalPeriodId): array;

    /**
     * @return array<string, mixed>
     */
    public function generateIncomeStatement(string $tenantId, string $fiscalPeriodId): array;

    /**
     * @return array<string, mixed>
     */
    public function generateCashFlowPreparation(string $tenantId, string $fiscalPeriodId): array;
}
