<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Listeners;

use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Projects\Events\ProjectAccrualReadyEvent;
use Modules\Projects\Events\ProjectClosureReadyEvent;
use Modules\Projects\Events\ProjectCostReadyEvent;
use Modules\Projects\Events\ProjectExpensePostingReadyEvent;
use Modules\Projects\Events\ProjectLabourCostReadyEvent;
use Modules\Projects\Events\ProjectMaterialCostReadyEvent;
use Modules\Projects\Events\ProjectRevenueReadyEvent;
use Modules\Projects\Events\ProjectRevenueRecognitionReadyEvent;
use Modules\Projects\Events\ProjectWipReadyEvent;

/**
 * Ledger postings of the Projects module (contabilità di commessa), through the double-entry posting service.
 *
 * Projects that recognise revenue over time or at completion capitalise their approved costs into work in progress
 * (1250 against the capitalised cost offset 5900); each recognition run adjusts the contract asset (1150) against
 * revenue (4000) and releases the cost of revenue (5000) from WIP; the closure settles both. Each source document is
 * posted once, so a redelivered event does not double the entry.
 */
final class ProjectsAccountingListener
{
    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly FiscalPeriodResolver $periods
    ) {}

    /**
     * Capitalises an approved project cost into contract work in progress (debit WIP, credit the capitalised cost
     * offset), once per cost entry. The cost by nature was booked by its source document.
     */
    public function handleCostReady(ProjectCostReadyEvent $event): void
    {
        if ($this->alreadyPosted($event->tenantId, 'project_cost_entry', $event->costEntryId)) {
            return;
        }

        $postingDate = $this->periods->dateOfEvent($event);
        $period = $this->periods->forDate($event->tenantId, $postingDate);

        $wipAcc = $this->resolveAccount($event->tenantId, $event->debitAccountCode, 'Work in progress on contracts (Lavori in corso su ordinazione)', 'asset', 'debit');
        $crAcc = $this->resolveAccount($event->tenantId, $event->creditAccountCode, 'Capitalized WIP cost offset (Variazione lavori in corso)', 'expense', 'credit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $postingDate,
            'document_date' => $postingDate,
            'entry_number' => 'PRJ-COST-'.strtoupper(Str::random(6)),
            'source_document_type' => 'project_cost_entry',
            'source_document_id' => $event->costEntryId,
            'journal_type' => 'standard',
            'description' => "Project Cost Posting: {$event->costCategory}",
            'lines' => [
                [
                    'account_id' => $wipAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => $event->totalCost,
                    'credit_amount' => '0',
                    'description' => "WIP Direct Cost: {$event->costCategory}",
                ],
                [
                    'account_id' => $crAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => '0',
                    'credit_amount' => $event->totalCost,
                    'description' => "Cost Absorption: {$event->costCategory}",
                ],
            ],
        ]);
    }

    public function handleRevenueReady(ProjectRevenueReadyEvent $event): void
    {
        $postingDate = $this->periods->dateOfEvent($event);
        $period = $this->periods->forDate($event->tenantId, $postingDate);

        $arAcc = $this->resolveAccount($event->tenantId, $event->debitAccountCode, 'Accounts Receivable (Crediti v/Clienti)', 'asset', 'debit');
        $arAcc->update(['is_reconciliation_account' => true, 'reconciliation_type' => 'customer']);
        $revAcc = $this->resolveAccount($event->tenantId, $event->creditAccountCode, 'Project Contract Revenue', 'revenue', 'credit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $postingDate,
            'document_date' => $postingDate,
            'entry_number' => 'PRJ-REV-'.strtoupper(Str::random(6)),
            'source_document_type' => 'project_revenue',
            'source_document_id' => $event->projectId,
            'journal_type' => 'standard',
            'description' => 'Project Direct Revenue Posting',
            'lines' => [
                [
                    'account_id' => $arAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => (float) $event->recognizedAmount,
                    'credit_amount' => 0.0,
                    'description' => 'Accounts Receivable for Project Revenue',
                ],
                [
                    'account_id' => $revAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => 0.0,
                    'credit_amount' => (float) $event->recognizedAmount,
                    'description' => 'Project Contract Revenue',
                ],
            ],
        ]);
    }

    public function handleWipReady(ProjectWipReadyEvent $event): void
    {
        $postingDate = $this->periods->dateOfEvent($event);
        $period = $this->periods->forDate($event->tenantId, $postingDate);

        $wipAcc = $this->resolveAccount($event->tenantId, $event->wipAssetAccountCode, 'Project Work In Progress (WIP)', 'asset', 'debit');
        $capAcc = $this->resolveAccount($event->tenantId, '5900', 'Capitalized Project WIP Clearing', 'expense', 'credit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $postingDate,
            'document_date' => $postingDate,
            'entry_number' => 'PRJ-WIP-'.strtoupper(Str::random(6)),
            'source_document_type' => 'project_wip',
            'source_document_id' => $event->projectId,
            'journal_type' => 'standard',
            'description' => "Periodic WIP Capitalization (as of {$event->asOfDate})",
            'lines' => [
                [
                    'account_id' => $wipAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => (float) $event->wipBalance,
                    'credit_amount' => 0.0,
                    'description' => 'WIP Asset Capitalization',
                ],
                [
                    'account_id' => $capAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => 0.0,
                    'credit_amount' => (float) $event->wipBalance,
                    'description' => 'WIP Offset Allocation',
                ],
            ],
        ]);
    }

    public function handleAccrualReady(ProjectAccrualReadyEvent $event): void
    {
        $postingDate = $this->periods->dateOfEvent($event);
        $period = $this->periods->forDate($event->tenantId, $postingDate);

        $expAcc = $this->resolveAccount($event->tenantId, $event->debitAccountCode, 'Accrued Project Expense', 'expense', 'debit');
        $liabAcc = $this->resolveAccount($event->tenantId, $event->creditAccountCode, 'Accrued Liabilities', 'liability', 'credit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $postingDate,
            'document_date' => $postingDate,
            'entry_number' => 'PRJ-ACCR-'.strtoupper(Str::random(6)),
            'source_document_type' => 'project_accrual',
            'source_document_id' => $event->projectId,
            'journal_type' => 'adjusting',
            'description' => "Project Expense Accrual: {$event->accrualType}",
            'lines' => [
                [
                    'account_id' => $expAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => (float) $event->accrualAmount,
                    'credit_amount' => 0.0,
                    'description' => 'Accrued Project Expense',
                ],
                [
                    'account_id' => $liabAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => 0.0,
                    'credit_amount' => (float) $event->accrualAmount,
                    'description' => 'Accrued Liability',
                ],
            ],
        ]);
    }

    /**
     * Final settlement of a closed project: the cost still capitalised in work in progress goes to the cost of revenue
     * and the residual contract asset is settled against revenue, so the result shows exactly the invoiced revenue.
     * Events without settlement amounts (projects that do not recognise revenue) post nothing.
     */
    public function handleClosureReady(ProjectClosureReadyEvent $event): void
    {
        $lines = [
            ...$this->wipReleaseLines($event->tenantId, $event->projectId, $event->wipReleaseAmount ?? '0', '5000', '1250'),
            ...$this->contractAssetLines($event->tenantId, $event->projectId, $event->contractAssetSettlement ?? '0', '1150', '4000'),
        ];

        if ($lines === [] || $this->alreadyPosted($event->tenantId, 'project_closure', $event->projectId)) {
            return;
        }

        $postingDate = $this->periods->dateOfEvent($event);
        $period = $this->periods->forDate($event->tenantId, $postingDate);

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $postingDate,
            'document_date' => $postingDate,
            'entry_number' => 'PRJ-CLOSE-'.strtoupper(Str::random(6)),
            'source_document_type' => 'project_closure',
            'source_document_id' => $event->projectId,
            'journal_type' => 'adjusting',
            'description' => 'Project closure: release WIP and settle contract asset',
            'lines' => $lines,
        ]);
    }

    /**
     * One revenue recognition run: the change of the contract asset against revenue (signed) and the release of the
     * capitalised cost of revenue from work in progress. Posted once per run.
     */
    public function handleRevenueRecognitionReady(ProjectRevenueRecognitionReadyEvent $event): void
    {
        $lines = [
            ...$this->contractAssetLines($event->tenantId, $event->projectId, $this->decimal($event->periodRevenueAdjustment), $event->debitAccountCode, $event->creditAccountCode),
            ...$this->wipReleaseLines($event->tenantId, $event->projectId, $event->periodCostOfRevenue, $event->costOfRevenueAccountCode, $event->workInProgressAccountCode),
        ];

        if ($lines === [] || $this->alreadyPosted($event->tenantId, 'revenue_recognition', $event->recognitionId)) {
            return;
        }

        $postingDate = $this->periods->dateOfEvent($event);
        $period = $this->periods->forDate($event->tenantId, $postingDate);
        $percentage = $this->decimal($event->costCompletionPercentage);

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $postingDate,
            'document_date' => $postingDate,
            'entry_number' => 'PRJ-REV-REC-'.strtoupper(Str::random(6)),
            'source_document_type' => 'revenue_recognition',
            'source_document_id' => $event->recognitionId,
            'journal_type' => 'adjusting',
            'description' => "Project revenue recognition: {$event->recognitionMethod} ({$percentage}%)",
            'lines' => $lines,
        ]);
    }

    /**
     * Cost by nature of an approved out-of-pocket project expense against the reimbursement payable, once per expense.
     */
    public function handleExpensePostingReady(ProjectExpensePostingReadyEvent $event): void
    {
        if ($this->alreadyPosted($event->tenantId, 'project_expense', $event->costEntryId)) {
            return;
        }

        $postingDate = $this->periods->dateOfEvent($event);
        $period = $this->periods->forDate($event->tenantId, $postingDate);

        $expAcc = $this->resolveAccount($event->tenantId, $event->debitAccountCode, 'Project Travel & Direct Expenses', 'expense', 'debit');
        $apAcc = $this->resolveAccount($event->tenantId, $event->creditAccountCode, 'Employee Expense Reimbursement Payable', 'liability', 'credit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $postingDate,
            'document_date' => $postingDate,
            'entry_number' => 'PRJ-EXP-'.strtoupper(Str::random(6)),
            'source_document_type' => 'project_expense',
            'source_document_id' => $event->costEntryId,
            'journal_type' => 'standard',
            'description' => "Project Expense Posting: {$event->expenseCategory}",
            'lines' => [
                [
                    'account_id' => $expAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => $event->amount,
                    'credit_amount' => '0',
                    'description' => 'Direct Project Expense',
                ],
                [
                    'account_id' => $apAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => '0',
                    'credit_amount' => $event->amount,
                    'description' => 'Expense Reimbursement Payable',
                ],
            ],
        ]);
    }

    /**
     * Lines moving a signed amount between the contract asset and revenue: positive debits the asset.
     *
     * @return list<array<string, mixed>>
     */
    private function contractAssetLines(string $tenantId, string $projectId, string $amount, string $assetCode, string $revenueCode): array
    {
        if (Decimal::isZero($amount)) {
            return [];
        }

        $asset = $this->resolveAccount($tenantId, $assetCode, 'Contract asset (Lavori da fatturare)', 'asset', 'debit');
        $revenue = $this->resolveAccount($tenantId, $revenueCode, 'Contract revenue (Ricavi su commesse)', 'revenue', 'credit');
        $value = Decimal::abs($amount);
        $increase = Decimal::isPositive($amount);

        return [
            ['account_id' => $asset->id, 'project_id' => $projectId, 'debit_amount' => $increase ? $value : '0', 'credit_amount' => $increase ? '0' : $value, 'description' => 'Contract asset (recognised minus invoiced revenue)'],
            ['account_id' => $revenue->id, 'project_id' => $projectId, 'debit_amount' => $increase ? '0' : $value, 'credit_amount' => $increase ? $value : '0', 'description' => 'Contract revenue adjustment'],
        ];
    }

    /**
     * Lines releasing a signed amount of capitalised cost from work in progress to the cost of revenue.
     *
     * @return list<array<string, mixed>>
     */
    private function wipReleaseLines(string $tenantId, string $projectId, string $amount, string $costCode, string $wipCode): array
    {
        if (Decimal::isZero($amount)) {
            return [];
        }

        $cost = $this->resolveAccount($tenantId, $costCode, 'Cost of Goods Sold (COGS)', 'cogs', 'debit');
        $wip = $this->resolveAccount($tenantId, $wipCode, 'Work in progress on contracts (Lavori in corso su ordinazione)', 'asset', 'debit');
        $value = Decimal::abs($amount);
        $release = Decimal::isPositive($amount);

        return [
            ['account_id' => $cost->id, 'project_id' => $projectId, 'debit_amount' => $release ? $value : '0', 'credit_amount' => $release ? '0' : $value, 'description' => 'Cost of revenue released from WIP'],
            ['account_id' => $wip->id, 'project_id' => $projectId, 'debit_amount' => $release ? '0' : $value, 'credit_amount' => $release ? $value : '0', 'description' => 'WIP relief'],
        ];
    }

    /**
     * Redelivered events must not post twice: one journal entry per project source document.
     */
    private function alreadyPosted(string $tenantId, string $sourceType, string $sourceId): bool
    {
        return JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source_document_type', $sourceType)
            ->where('source_document_id', $sourceId)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function decimal(float|string $value): string
    {
        return is_float($value) ? number_format($value, 4, '.', '') : $value;
    }

    public function handleLabourCostReady(ProjectLabourCostReadyEvent $event): void
    {
        $postingDate = $this->periods->dateOfEvent($event);
        $period = $this->periods->forDate($event->tenantId, $postingDate);

        $wipAcc = $this->resolveAccount($event->tenantId, $event->debitAccountCode, 'Project Work In Progress (WIP - Labor)', 'asset', 'debit');
        $payrollAcc = $this->resolveAccount($event->tenantId, $event->creditAccountCode, 'Direct Labor Payroll Absorption', 'liability', 'credit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $postingDate,
            'document_date' => $postingDate,
            'entry_number' => 'PRJ-LAB-'.strtoupper(Str::random(6)),
            'source_document_type' => 'project_timesheet',
            'source_document_id' => $event->timesheetId,
            'journal_type' => 'standard',
            'description' => "Direct Labor Hours Absorption ({$event->hoursWorked} hrs)",
            'lines' => [
                [
                    'account_id' => $wipAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => (float) $event->totalCost,
                    'credit_amount' => 0.0,
                    'description' => "WIP Direct Labor ({$event->hoursWorked} hrs)",
                ],
                [
                    'account_id' => $payrollAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => 0.0,
                    'credit_amount' => (float) $event->totalCost,
                    'description' => 'Labor Payroll Absorption Clearing',
                ],
            ],
        ]);
    }

    public function handleMaterialCostReady(ProjectMaterialCostReadyEvent $event): void
    {
        $postingDate = $this->periods->dateOfEvent($event);
        $period = $this->periods->forDate($event->tenantId, $postingDate);

        $wipAcc = $this->resolveAccount($event->tenantId, $event->debitAccountCode, 'Project Work In Progress (WIP - Material)', 'asset', 'debit');
        $invAcc = $this->resolveAccount($event->tenantId, $event->creditAccountCode, 'Merchandise Inventory Asset', 'asset', 'debit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $postingDate,
            'document_date' => $postingDate,
            'entry_number' => 'PRJ-MAT-'.strtoupper(Str::random(6)),
            'source_document_type' => 'project_material',
            'source_document_id' => $event->costEntryId,
            'journal_type' => 'standard',
            'description' => "Project Material Consumption ({$event->quantity} units)",
            'lines' => [
                [
                    'account_id' => $wipAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => (float) $event->totalCost,
                    'credit_amount' => 0.0,
                    'description' => "WIP Direct Material ({$event->quantity} units)",
                ],
                [
                    'account_id' => $invAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => 0.0,
                    'credit_amount' => (float) $event->totalCost,
                    'description' => 'Inventory Asset Relief for Project',
                ],
            ],
        ]);
    }

    private function resolveAccount(string $tenantId, string $code, string $name, string $type, string $normalBalance): ChartOfAccount
    {
        return ChartOfAccount::firstOrCreate(
            ['tenant_id' => $tenantId, 'account_code' => $code],
            [
                'id' => (string) Str::uuid7(),
                'name' => $name,
                'account_class' => $type,
                'account_type' => $type,
                'normal_balance' => $normalBalance,
                'currency' => TenantAccountingCurrency::of($tenantId),
                'is_active' => true,
            ]
        );
    }
}
