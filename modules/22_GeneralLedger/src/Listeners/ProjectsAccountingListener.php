<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Listeners;

use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\Projects\Events\ProjectAccrualReadyEvent;
use Modules\Projects\Events\ProjectClosureReadyEvent;
use Modules\Projects\Events\ProjectCostReadyEvent;
use Modules\Projects\Events\ProjectExpensePostingReadyEvent;
use Modules\Projects\Events\ProjectLabourCostReadyEvent;
use Modules\Projects\Events\ProjectMarginCalculatedEvent;
use Modules\Projects\Events\ProjectMaterialCostReadyEvent;
use Modules\Projects\Events\ProjectRevenueReadyEvent;
use Modules\Projects\Events\ProjectRevenueRecognitionReadyEvent;
use Modules\Projects\Events\ProjectWipReadyEvent;

final class ProjectsAccountingListener
{
    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService
    ) {}

    public function handleCostReady(ProjectCostReadyEvent $event): void
    {
        $period = $this->resolveFiscalPeriod($event->tenantId);
        if (! $period) {
            return;
        }

        $wipAcc = $this->resolveAccount($event->tenantId, $event->debitAccountCode, 'Project Work In Progress (WIP)', 'asset', 'debit');
        $crAcc = $this->resolveAccount($event->tenantId, $event->creditAccountCode, 'Cost Allocation Clearing', 'liability', 'credit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'PRJ-COST-'.strtoupper(Str::random(6)),
            'source_document_type' => 'project_cost_entry',
            'source_document_id' => $event->costEntryId,
            'journal_type' => 'standard',
            'description' => "Project Cost Posting: {$event->costCategory}",
            'lines' => [
                [
                    'account_id' => $wipAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => (float) $event->totalCost,
                    'credit_amount' => 0.0,
                    'description' => "WIP Direct Cost: {$event->costCategory}",
                ],
                [
                    'account_id' => $crAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => 0.0,
                    'credit_amount' => (float) $event->totalCost,
                    'description' => "Cost Absorption: {$event->costCategory}",
                ],
            ],
        ]);
    }

    public function handleRevenueReady(ProjectRevenueReadyEvent $event): void
    {
        $period = $this->resolveFiscalPeriod($event->tenantId);
        if (! $period) {
            return;
        }

        $arAcc = $this->resolveAccount($event->tenantId, $event->debitAccountCode, 'Accounts Receivable (Crediti v/Clienti)', 'asset', 'debit');
        $arAcc->update(['is_reconciliation_account' => true, 'reconciliation_type' => 'customer']);
        $revAcc = $this->resolveAccount($event->tenantId, $event->creditAccountCode, 'Project Contract Revenue', 'revenue', 'credit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'PRJ-REV-'.strtoupper(Str::random(6)),
            'source_document_type' => 'project_revenue',
            'source_document_id' => $event->projectId,
            'journal_type' => 'standard',
            'description' => 'Project Direct Revenue Posting',
            'lines' => [
                [
                    'account_id' => $arAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => (float) $event->totalRevenue,
                    'credit_amount' => 0.0,
                    'description' => 'Accounts Receivable for Project Revenue',
                ],
                [
                    'account_id' => $revAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => 0.0,
                    'credit_amount' => (float) $event->totalRevenue,
                    'description' => 'Project Contract Revenue',
                ],
            ],
        ]);
    }

    public function handleWipReady(ProjectWipReadyEvent $event): void
    {
        $period = $this->resolveFiscalPeriod($event->tenantId);
        if (! $period) {
            return;
        }

        $wipAcc = $this->resolveAccount($event->tenantId, $event->wipAccountCode, 'Project Work In Progress (WIP)', 'asset', 'debit');
        $capAcc = $this->resolveAccount($event->tenantId, '5900', 'Capitalized Project WIP Clearing', 'expense', 'credit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'PRJ-WIP-'.strtoupper(Str::random(6)),
            'source_document_type' => 'project_wip',
            'source_document_id' => $event->projectId,
            'journal_type' => 'standard',
            'description' => "Periodic WIP Capitalization ({$event->calculationMethod})",
            'lines' => [
                [
                    'account_id' => $wipAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => (float) $event->wipAmount,
                    'credit_amount' => 0.0,
                    'description' => 'WIP Asset Capitalization',
                ],
                [
                    'account_id' => $capAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => 0.0,
                    'credit_amount' => (float) $event->wipAmount,
                    'description' => 'WIP Offset Allocation',
                ],
            ],
        ]);
    }

    public function handleAccrualReady(ProjectAccrualReadyEvent $event): void
    {
        $period = $this->resolveFiscalPeriod($event->tenantId);
        if (! $period) {
            return;
        }

        $expAcc = $this->resolveAccount($event->tenantId, $event->debitAccountCode, 'Accrued Project Expense', 'expense', 'debit');
        $liabAcc = $this->resolveAccount($event->tenantId, $event->creditAccountCode, 'Accrued Liabilities', 'liability', 'credit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
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

    public function handleMarginCalculated(ProjectMarginCalculatedEvent $event): void
    {
        // Margin analysis entry logging - informational balancing entry if profit recognized
        $period = $this->resolveFiscalPeriod($event->tenantId);
        if (! $period || (float) $event->grossMargin <= 0) {
            return;
        }

        $marginAcc = $this->resolveAccount($event->tenantId, '4900', 'Project Margin Analysis Stat Account', 'statistical', 'debit');
        $statClearing = $this->resolveAccount($event->tenantId, '4990', 'Statistical Clearing Account', 'statistical', 'credit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'PRJ-MRG-'.strtoupper(Str::random(6)),
            'source_document_type' => 'project_margin',
            'source_document_id' => $event->projectId,
            'journal_type' => 'standard',
            'description' => "Statistical Margin Logging: Margin Pct {$event->marginPercentage}%",
            'lines' => [
                [
                    'account_id' => $marginAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => (float) $event->grossMargin,
                    'credit_amount' => 0.0,
                    'description' => 'Project Statistical Margin Debit',
                ],
                [
                    'account_id' => $statClearing->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => 0.0,
                    'credit_amount' => (float) $event->grossMargin,
                    'description' => 'Project Statistical Margin Credit',
                ],
            ],
        ]);
    }

    public function handleClosureReady(ProjectClosureReadyEvent $event): void
    {
        $period = $this->resolveFiscalPeriod($event->tenantId);
        if (! $period) {
            return;
        }

        $cogsAcc = $this->resolveAccount($event->tenantId, '5000', 'Cost of Goods Sold (COGS)', 'cogs', 'debit');
        $wipAcc = $this->resolveAccount($event->tenantId, '1250', 'Project Work In Progress (WIP)', 'asset', 'debit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'PRJ-CLOSE-'.strtoupper(Str::random(6)),
            'source_document_type' => 'project_closure',
            'source_document_id' => $event->projectId,
            'journal_type' => 'closing',
            'description' => 'Final Project Closure: Clear WIP to COGS',
            'lines' => [
                [
                    'account_id' => $cogsAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => (float) $event->finalActualCost,
                    'credit_amount' => 0.0,
                    'description' => 'COGS Final Cost Recognition',
                ],
                [
                    'account_id' => $wipAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => 0.0,
                    'credit_amount' => (float) $event->finalActualCost,
                    'description' => 'WIP Relief upon Closure',
                ],
            ],
        ]);
    }

    public function handleRevenueRecognitionReady(ProjectRevenueRecognitionReadyEvent $event): void
    {
        $period = $this->resolveFiscalPeriod($event->tenantId);
        if (! $period) {
            return;
        }

        $assetAcc = $this->resolveAccount($event->tenantId, $event->debitAccountCode, 'Contract Asset / Unbilled AR', 'asset', 'debit');
        $revAcc = $this->resolveAccount($event->tenantId, $event->creditAccountCode, 'Recognized Contract Revenue (ASC 606)', 'revenue', 'credit');

        $method = $event->recognitionMethod ?? 'percentage_of_completion';
        $pct = $event->percentageComplete ?? $event->costCompletionPercentage ?? 100.0;
        $revAmount = (float) ($event->recognizedRevenue ?? $event->periodRevenueAdjustment ?? $event->cumulativeRecognizedRevenue ?? 0.0);

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'PRJ-REV-REC-'.strtoupper(Str::random(6)),
            'source_document_type' => 'revenue_recognition',
            'source_document_id' => $event->recognitionId,
            'journal_type' => 'adjusting',
            'description' => "POC Revenue Recognition: {$method} ({$pct}%)",
            'lines' => [
                [
                    'account_id' => $assetAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => $revAmount,
                    'credit_amount' => 0.0,
                    'description' => 'Contract Asset (Unbilled Receivables)',
                ],
                [
                    'account_id' => $revAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => 0.0,
                    'credit_amount' => $revAmount,
                    'description' => 'POC Recognized Revenue',
                ],
            ],
        ]);
    }

    public function handleExpensePostingReady(ProjectExpensePostingReadyEvent $event): void
    {
        $period = $this->resolveFiscalPeriod($event->tenantId);
        if (! $period) {
            return;
        }

        $expAcc = $this->resolveAccount($event->tenantId, $event->debitAccountCode, 'Project Travel & Direct Expenses', 'expense', 'debit');
        $apAcc = $this->resolveAccount($event->tenantId, $event->creditAccountCode, 'Employee Expense Reimbursement Payable', 'liability', 'credit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'PRJ-EXP-'.strtoupper(Str::random(6)),
            'source_document_type' => 'project_expense',
            'source_document_id' => $event->expenseId,
            'journal_type' => 'standard',
            'description' => "Project Expense Posting: {$event->expenseCategory}",
            'lines' => [
                [
                    'account_id' => $expAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => (float) $event->amount,
                    'credit_amount' => 0.0,
                    'description' => 'Direct Project Expense',
                ],
                [
                    'account_id' => $apAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => 0.0,
                    'credit_amount' => (float) $event->amount,
                    'description' => 'Expense Reimbursement Payable',
                ],
            ],
        ]);
    }

    public function handleLabourCostReady(ProjectLabourCostReadyEvent $event): void
    {
        $period = $this->resolveFiscalPeriod($event->tenantId);
        if (! $period) {
            return;
        }

        $wipAcc = $this->resolveAccount($event->tenantId, $event->debitAccountCode, 'Project Work In Progress (WIP - Labor)', 'asset', 'debit');
        $payrollAcc = $this->resolveAccount($event->tenantId, $event->creditAccountCode, 'Direct Labor Payroll Absorption', 'liability', 'credit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'PRJ-LAB-'.strtoupper(Str::random(6)),
            'source_document_type' => 'project_timesheet',
            'source_document_id' => $event->timesheetId,
            'journal_type' => 'standard',
            'description' => "Direct Labor Hours Absorption ({$event->hours} hrs)",
            'lines' => [
                [
                    'account_id' => $wipAcc->id,
                    'project_id' => $event->projectId,
                    'debit_amount' => (float) $event->totalCost,
                    'credit_amount' => 0.0,
                    'description' => "WIP Direct Labor ({$event->hours} hrs)",
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
        $period = $this->resolveFiscalPeriod($event->tenantId);
        if (! $period) {
            return;
        }

        $wipAcc = $this->resolveAccount($event->tenantId, $event->debitAccountCode, 'Project Work In Progress (WIP - Material)', 'asset', 'debit');
        $invAcc = $this->resolveAccount($event->tenantId, $event->creditAccountCode, 'Merchandise Inventory Asset', 'asset', 'debit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'PRJ-MAT-'.strtoupper(Str::random(6)),
            'source_document_type' => 'project_material',
            'source_document_id' => $event->consumptionId,
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

    private function resolveFiscalPeriod(string $tenantId): ?FiscalPeriod
    {
        return FiscalPeriod::where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->first();
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
                'currency' => 'EUR',
                'is_active' => true,
            ]
        );
    }
}
