<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Contracts\TaxEngineReadinessServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\VatSettlement;

final class TaxEngineReadinessService implements TaxEngineReadinessServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly DoubleEntryPostingServiceInterface $postingService
    ) {}

    public function calculateVatSettlement(string $tenantId, string $fiscalPeriodId, ?string $actorUserId = null): VatSettlement
    {
        $period = FiscalPeriod::findOrFail($fiscalPeriodId);

        // Calculate Output VAT (Sales)
        $outputVat = (float) DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.fiscal_period_id', $fiscalPeriodId)
            ->where(function ($q) {
                $q->where('chart_of_accounts.account_code', '2200')
                    ->orWhere('chart_of_accounts.name', 'like', '%Sales VAT%')
                    ->orWhere('chart_of_accounts.name', 'like', '%IVA c/Vendite%');
            })
            ->sum('journal_lines.credit_amount');

        // Calculate Input VAT (Purchases)
        $inputVat = (float) DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.fiscal_period_id', $fiscalPeriodId)
            ->where(function ($q) {
                $q->where('chart_of_accounts.account_code', '1400')
                    ->orWhere('chart_of_accounts.name', 'like', '%Purchase VAT%')
                    ->orWhere('chart_of_accounts.name', 'like', '%IVA c/Acquisti%');
            })
            ->sum('journal_lines.debit_amount');

        $netPayable = 0.0;
        $netCredit = 0.0;

        if ($outputVat >= $inputVat) {
            $netPayable = round($outputVat - $inputVat, 4);
        } else {
            $netCredit = round($inputVat - $outputVat, 4);
        }

        return VatSettlement::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'fiscal_period_id' => $period->id,
            ],
            [
                'id' => (string) Str::uuid7(),
                'company_id' => $period->company_id,
                'settlement_period' => $period->period_name,
                'output_vat_amount' => $outputVat,
                'input_vat_amount' => $inputVat,
                'prior_credit_used' => 0.0,
                'net_vat_payable' => $netPayable,
                'net_vat_credit' => $netCredit,
                'status' => 'calculated',
                'calculated_by' => $actorUserId,
            ]
        );
    }

    public function postVatSettlement(string $vatSettlementId, ?string $actorUserId = null): JournalEntry
    {
        $settlement = VatSettlement::findOrFail($vatSettlementId);

        if ($settlement->status === 'posted') {
            throw new InvalidArgumentException('VAT settlement is already posted.');
        }

        $period = FiscalPeriod::findOrFail($settlement->fiscal_period_id);

        $salesVatAcc = ChartOfAccount::firstOrCreate(
            ['tenant_id' => $settlement->tenant_id, 'account_code' => '2200'],
            [
                'id' => (string) Str::uuid7(),
                'name' => 'IVA c/Vendite (Sales VAT)',
                'account_class' => 'liability',
                'account_type' => 'liability',
                'normal_balance' => 'credit',
                'is_active' => true,
            ]
        );

        $purchVatAcc = ChartOfAccount::firstOrCreate(
            ['tenant_id' => $settlement->tenant_id, 'account_code' => '1400'],
            [
                'id' => (string) Str::uuid7(),
                'name' => 'IVA c/Acquisti (Purchase VAT)',
                'account_class' => 'asset',
                'account_type' => 'asset',
                'normal_balance' => 'debit',
                'is_active' => true,
            ]
        );

        $erarioVatAcc = ChartOfAccount::firstOrCreate(
            ['tenant_id' => $settlement->tenant_id, 'account_code' => '2210'],
            [
                'id' => (string) Str::uuid7(),
                'name' => 'Erario c/IVA (Tax Authority VAT Settlement)',
                'account_class' => 'liability',
                'account_type' => 'liability',
                'normal_balance' => 'credit',
                'is_active' => true,
            ]
        );

        $lines = [];

        // Debit Sales VAT to clear credit
        if ((float) $settlement->output_vat_amount > 0) {
            $lines[] = [
                'account_id' => $salesVatAcc->id,
                'debit_amount' => (float) $settlement->output_vat_amount,
                'credit_amount' => 0.0,
                'description' => 'Clear Sales VAT for settlement',
            ];
        }

        // Credit Purchase VAT to clear debit
        if ((float) $settlement->input_vat_amount > 0) {
            $lines[] = [
                'account_id' => $purchVatAcc->id,
                'debit_amount' => 0.0,
                'credit_amount' => (float) $settlement->input_vat_amount,
                'description' => 'Clear Purchase VAT for settlement',
            ];
        }

        // Difference to Erario c/IVA
        if ((float) $settlement->net_vat_payable > 0) {
            $lines[] = [
                'account_id' => $erarioVatAcc->id,
                'debit_amount' => 0.0,
                'credit_amount' => (float) $settlement->net_vat_payable,
                'description' => 'Net VAT Payable to Tax Authority',
            ];
        } elseif ((float) $settlement->net_vat_credit > 0) {
            $lines[] = [
                'account_id' => $erarioVatAcc->id,
                'debit_amount' => (float) $settlement->net_vat_credit,
                'credit_amount' => 0.0,
                'description' => 'Net VAT Credit toward Tax Authority',
            ];
        }

        $journal = $this->postingService->createAndPost([
            'tenant_id' => $settlement->tenant_id,
            'company_id' => $settlement->company_id,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'VAT-SETTLE-'.strtoupper(Str::random(6)),
            'posting_date' => $period->end_date,
            'document_date' => $period->end_date,
            'journal_type' => 'adjusting',
            'description' => "Periodic VAT Settlement for {$period->period_name}",
            'lines' => $lines,
        ], $actorUserId);

        $settlement->update([
            'status' => 'posted',
            'settlement_journal_id' => $journal->id,
        ]);

        return $journal;
    }
}
