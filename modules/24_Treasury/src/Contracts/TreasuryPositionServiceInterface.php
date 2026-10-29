<?php

declare(strict_types=1);

namespace Modules\Treasury\Contracts;

use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountBalance;

interface TreasuryPositionServiceInterface
{
    /**
     * Compute current intraday multi-bank and multi-currency cash positions.
     *
     * @return array<string, mixed>
     */
    public function getIntradayPosition(string $tenantId, ?string $companyId = null, ?string $currency = null): array;

    /**
     * Update bank account balance from transaction or statement.
     */
    public function updateBalance(string $bankAccountId, float $deltaAmount, string $source = 'transaction'): BankAccount;

    /**
     * Snapshot end-of-day balances across all active bank accounts.
     *
     * @return array<int, BankAccountBalance>
     */
    public function snapshotDailyBalances(string $tenantId, ?string $date = null): array;

    /**
     * Calculate total liquidity, including available vs. blocked collateral cash.
     *
     * @return array{total_cash: float, available_cash: float, blocked_cash: float, overdraft_utilized: float, currency: string}
     */
    public function calculateLiquidityBuffer(string $tenantId, ?string $companyId = null): array;
}
