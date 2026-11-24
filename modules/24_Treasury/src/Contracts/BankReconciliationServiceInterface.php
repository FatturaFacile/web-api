<?php

declare(strict_types=1);

namespace Modules\Treasury\Contracts;

use Modules\Treasury\Models\BankReconciliation;
use Modules\Treasury\Models\BankStatement;

interface BankReconciliationServiceInterface
{
    /**
     * Ingest electronic bank statement (CAMT.053, MT940, CSV).
     *
     * @param  array<string, mixed>  $statementData
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function importStatement(array $statementData, array $lines): BankStatement;

    /**
     * Execute dual-pass automated reconciliation against GL and subledgers.
     */
    public function runAutoReconciliation(string $bankStatementId, ?string $userId = null): BankReconciliation;

    /**
     * Manually match a bank statement line to a transaction or journal entry.
     *
     * @param  array<string, mixed>  $matchData
     */
    public function matchManually(string $bankStatementLineId, array $matchData, string $userId): void;

    /**
     * Finalize and approve bank reconciliation.
     */
    public function approveReconciliation(string $reconciliationId, string $approvedByUserId): BankReconciliation;
}
