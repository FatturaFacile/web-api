<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\AccountingReconciliationServiceInterface;
use Modules\GeneralLedger\Events\AccountReconciledEvent;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\CustomerLedger;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\ReconciliationItem;
use Modules\GeneralLedger\Models\ReconciliationRun;
use Modules\GeneralLedger\Models\SupplierLedger;

final class AccountingReconciliationService implements AccountingReconciliationServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher
    ) {}

    public function reconcileSubledger(string $tenantId, string $reconciliationType, string $fiscalPeriodId, ?string $actorUserId = null): ReconciliationRun
    {
        $period = FiscalPeriod::findOrFail($fiscalPeriodId);

        if ($reconciliationType === 'subledger_ar') {
            $account = ChartOfAccount::where('tenant_id', $tenantId)
                ->where('reconciliation_type', 'customer')
                ->first() ?? ChartOfAccount::where('tenant_id', $tenantId)->where('account_code', '1100')->firstOrFail();

            // GL balance: net debits
            $glBalance = (float) DB::table('journal_lines')
                ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
                ->where('journal_entries.tenant_id', $tenantId)
                ->where('journal_entries.status', 'posted')
                ->where('journal_lines.account_id', $account->id)
                ->sum(DB::raw('journal_lines.debit_amount - journal_lines.credit_amount'));

            // Subledger balance: sum of open customer ledger items
            $subledgerBalance = (float) CustomerLedger::where('tenant_id', $tenantId)
                ->whereIn('status', ['open', 'partially_paid'])
                ->sum('open_amount');
        } elseif ($reconciliationType === 'subledger_ap') {
            $account = ChartOfAccount::where('tenant_id', $tenantId)
                ->where('reconciliation_type', 'supplier')
                ->first() ?? ChartOfAccount::where('tenant_id', $tenantId)->where('account_code', '2000')->firstOrFail();

            // GL balance: net credits
            $glBalance = (float) DB::table('journal_lines')
                ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
                ->where('journal_entries.tenant_id', $tenantId)
                ->where('journal_entries.status', 'posted')
                ->where('journal_lines.account_id', $account->id)
                ->sum(DB::raw('journal_lines.credit_amount - journal_lines.debit_amount'));

            // Subledger balance: sum of open supplier ledger items
            $subledgerBalance = (float) SupplierLedger::where('tenant_id', $tenantId)
                ->whereIn('status', ['open', 'partially_paid'])
                ->sum('open_amount');
        } else {
            // General / Inventory / Project
            $account = ChartOfAccount::where('tenant_id', $tenantId)
                ->where('reconciliation_type', $reconciliationType)
                ->first() ?? ChartOfAccount::where('tenant_id', $tenantId)->firstOrFail();

            $glBalance = (float) DB::table('journal_lines')
                ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
                ->where('journal_entries.tenant_id', $tenantId)
                ->where('journal_entries.status', 'posted')
                ->where('journal_lines.account_id', $account->id)
                ->sum(DB::raw('journal_lines.debit_amount - journal_lines.credit_amount'));

            $subledgerBalance = $glBalance; // balanced
        }

        $variance = round($glBalance - $subledgerBalance, 4);
        $status = abs($variance) < 0.0001 ? 'balanced' : 'discrepancy';

        $run = ReconciliationRun::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => $period->company_id,
            'reconciliation_type' => $reconciliationType,
            'period_id' => $period->id,
            'account_id' => $account->id,
            'status' => $status,
            'gl_balance' => $glBalance,
            'subledger_balance' => $subledgerBalance,
            'variance_amount' => $variance,
            'reconciled_by' => $actorUserId,
            'reconciled_at' => now(),
            'notes' => "Reconciliation run for {$reconciliationType} in {$period->period_name}",
        ]);

        ReconciliationItem::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'reconciliation_run_id' => $run->id,
            'item_reference' => 'SUMMARY-'.strtoupper($reconciliationType),
            'item_amount' => $glBalance,
            'variance_amount' => $variance,
            'is_matched' => $status === 'balanced',
            'match_type' => 'automatic',
        ]);

        $event = new AccountReconciledEvent(
            reconciliationRunId: (string) $run->id,
            reconciliationType: $reconciliationType,
            accountId: (string) $account->id,
            varianceAmount: $variance,
            isBalanced: $status === 'balanced',
            tenantId: $tenantId,
            companyId: (string) ($period->company_id ?? '')
        );
        $this->dispatcher->publish($event);
        event($event);

        return $run->load('items');
    }

    public function reconcileGrIr(string $tenantId, string $fiscalPeriodId, ?string $actorUserId = null): ReconciliationRun
    {
        $period = FiscalPeriod::findOrFail($fiscalPeriodId);

        $account = ChartOfAccount::where('tenant_id', $tenantId)
            ->where(function ($q) {
                $q->where('reconciliation_type', 'gr_ir')
                    ->orWhere('account_code', '2010');
            })
            ->first();

        if (! $account) {
            $account = ChartOfAccount::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'company_id' => $period->company_id,
                'account_code' => '2010',
                'name' => 'GR/IR Clearing Account',
                'account_class' => 'liability',
                'account_type' => 'liability',
                'normal_balance' => 'credit',
                'reconciliation_type' => 'gr_ir',
                'is_reconciliation_account' => true,
                'is_active' => true,
            ]);
        }

        // Net balance of GR/IR clearing account
        $dr = (float) DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', 'posted')
            ->where('journal_lines.account_id', $account->id)
            ->sum('journal_lines.debit_amount');

        $cr = (float) DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', 'posted')
            ->where('journal_lines.account_id', $account->id)
            ->sum('journal_lines.credit_amount');

        $netClearing = round($cr - $dr, 4);
        $status = abs($netClearing) < 0.0001 ? 'balanced' : 'discrepancy';

        $run = ReconciliationRun::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => $period->company_id,
            'reconciliation_type' => 'gr_ir',
            'period_id' => $period->id,
            'account_id' => $account->id,
            'status' => $status,
            'gl_balance' => $cr,
            'subledger_balance' => $dr,
            'variance_amount' => $netClearing,
            'reconciled_by' => $actorUserId,
            'reconciled_at' => now(),
            'notes' => 'Goods Received / Invoice Received (GR/IR) clearing reconciliation',
        ]);

        ReconciliationItem::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'reconciliation_run_id' => $run->id,
            'item_reference' => 'GRIR-NET-CLEARING',
            'item_amount' => $cr,
            'variance_amount' => $netClearing,
            'is_matched' => $status === 'balanced',
            'match_type' => 'automatic',
        ]);

        $event = new AccountReconciledEvent(
            reconciliationRunId: (string) $run->id,
            reconciliationType: 'gr_ir',
            accountId: (string) $account->id,
            varianceAmount: $netClearing,
            isBalanced: $status === 'balanced',
            tenantId: $tenantId,
            companyId: (string) ($period->company_id ?? '')
        );
        $this->dispatcher->publish($event);
        event($event);

        return $run->load('items');
    }
}
