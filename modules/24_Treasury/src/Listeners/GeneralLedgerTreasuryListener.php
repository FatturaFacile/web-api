<?php

declare(strict_types=1);

namespace Modules\Treasury\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Events\AccountReconciledEvent;
use Modules\GeneralLedger\Events\FinancialStatementGeneratedEvent;
use Modules\GeneralLedger\Events\JournalPostedEvent;
use Modules\GeneralLedger\Events\JournalReversedEvent;
use Modules\GeneralLedger\Events\PeriodClosedEvent;
use Modules\GeneralLedger\Events\PeriodOpenedEvent;
use Modules\GeneralLedger\Events\TrialBalanceGeneratedEvent;
use Modules\GeneralLedger\Events\YearClosedEvent;
use Modules\Treasury\Contracts\TreasuryPositionServiceInterface;

final readonly class GeneralLedgerTreasuryListener
{
    public function __construct(
        private TreasuryPositionServiceInterface $positionService,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function handleJournalPosted(JournalPostedEvent $event): void
    {
        $account = BankAccount::where('tenant_id', $event->tenantId)->first()
            ?? new BankAccount(['id' => (string) Str::uuid7(), 'tenant_id' => $event->tenantId]);

        $this->auditLedger->record(
            action: 'gl_journal_posted_ingested',
            model: $account,
            payloadBefore: null,
            payloadAfter: [
                'journal_id' => $event->journalEntryId,
                'entry_number' => $event->entryNumber,
                'total_amount' => $event->totalAmount,
            ],
            moduleCode: 'MODULE_24_TREASURY'
        );
    }

    public function handleJournalReversed(JournalReversedEvent $event): void
    {
        $account = BankAccount::where('tenant_id', $event->tenantId)->first()
            ?? new BankAccount(['id' => (string) Str::uuid7(), 'tenant_id' => $event->tenantId]);

        $this->auditLedger->record(
            action: 'gl_journal_reversed_ingested',
            model: $account,
            payloadBefore: null,
            payloadAfter: [
                'original_id' => $event->originalJournalEntryId,
                'reversal_id' => $event->reversalJournalEntryId,
            ],
            moduleCode: 'MODULE_24_TREASURY'
        );
    }

    public function handlePeriodClosed(PeriodClosedEvent $event): void
    {
        // Snapshot end-of-period balances on period close
        $this->positionService->snapshotDailyBalances($event->tenantId);
    }

    public function handlePeriodOpened(PeriodOpenedEvent $event): void
    {
        $this->positionService->getIntradayPosition($event->tenantId);
    }

    public function handleYearClosed(YearClosedEvent $event): void
    {
        $this->positionService->snapshotDailyBalances($event->tenantId);
    }

    public function handleTrialBalanceGenerated(TrialBalanceGeneratedEvent $event): void
    {
        // Re-verify liquidity positions against trial balance
        $this->positionService->calculateLiquidityBuffer($event->tenantId);
    }

    public function handleFinancialStatementGenerated(FinancialStatementGeneratedEvent $event): void
    {
        $account = BankAccount::where('tenant_id', $event->tenantId)->first()
            ?? new BankAccount(['id' => (string) Str::uuid7(), 'tenant_id' => $event->tenantId]);

        $this->auditLedger->record(
            action: 'financial_statement_observed',
            model: $account,
            payloadBefore: null,
            payloadAfter: [
                'statement_type' => $event->statementType,
                'net_amount' => $event->netAmount,
            ],
            moduleCode: 'MODULE_24_TREASURY'
        );
    }

    public function handleAccountReconciled(AccountReconciledEvent $event): void
    {
        $account = BankAccount::where('tenant_id', $event->tenantId)->first()
            ?? new BankAccount(['id' => (string) Str::uuid7(), 'tenant_id' => $event->tenantId]);

        $this->auditLedger->record(
            action: 'gl_account_reconciled_observed',
            model: $account,
            payloadBefore: null,
            payloadAfter: [
                'reconciliation_run_id' => $event->reconciliationRunId,
                'account_id' => $event->accountId,
            ],
            moduleCode: 'MODULE_24_TREASURY'
        );
    }
}
