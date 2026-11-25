<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\ExpenseManagement\Contracts\ProjectRebillingInterface;
use Modules\ExpenseManagement\Domain\Enums\PaymentMethod;
use Modules\ExpenseManagement\Domain\Enums\PayoutChannel;
use Modules\ExpenseManagement\Domain\Enums\ReportStatus;
use Modules\ExpenseManagement\Events\ExpensePayrollItemsReadyEvent;
use Modules\ExpenseManagement\Events\ExpenseReportPostedEvent;
use Modules\ExpenseManagement\Exceptions\ExpenseException;
use Modules\ExpenseManagement\Models\ExpenseLine;
use Modules\ExpenseManagement\Models\ExpensePayout;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Support\Decimal;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Treasury\Models\BankAccount;

/**
 * Pays out an approved report: a pain.001 credit transfer file (Mod 016) or payroll items (Mod 076 / 077), the journal
 * entry for the General Ledger and the project cost of the billable lines (Mod 081). Nothing leaves the system by itself:
 * the file is stored for the treasurer to send, the rest goes out as events and through the rebilling contract.
 */
final class PayoutService
{
    public function __construct(private readonly ReportService $reports, private readonly ProjectRebillingInterface $rebilling) {}

    public function export(ExpenseReport $report, PayoutChannel $channel, ?string $executionDate, string $userId): ExpensePayout
    {
        $this->reports->assertCanMove($report, ReportStatus::Posted);

        $amount = $report->approved_amount;

        if (! Decimal::isPositive($amount)) {
            throw new ExpenseException('no_reimbursable_amount');
        }

        $taxable = Decimal::min($report->taxable_amount, $amount);
        $exempt = Decimal::sub($amount, $taxable);
        $date = $executionDate ?? now()->addDay()->toDateString();

        return DB::transaction(function () use ($report, $channel, $date, $amount, $taxable, $exempt, $userId): ExpensePayout {
            $messageId = 'EXP'.strtoupper(Str::random(12));
            $payout = new ExpensePayout([
                'tenant_id' => $report->tenant_id,
                'expense_report_id' => $report->id,
                'channel' => $channel->value,
                'status' => 'exported',
                'message_id' => $messageId,
                'total_amount' => $amount,
                'taxable_amount' => $taxable,
                'exempt_amount' => $exempt,
                'currency' => $report->currency,
                'execution_date' => $date,
            ]);

            if ($channel === PayoutChannel::Pain001) {
                $xml = $this->pain001($report, $messageId, $amount, $date);
                $path = 'expense-payouts/'.$report->tenant_id.'/'.$messageId.'.xml';
                Storage::disk('local')->put($path, $xml);
                $payout->fill(['file_name' => $messageId.'.xml', 'disk' => 'local', 'path' => $path]);
            } else {
                $items = $this->payrollItems($report, $exempt, $taxable);
                $payout->payload = ['items' => $items];
                event(new ExpensePayrollItemsReadyEvent((string) $report->id, $report->report_number, $report->employee_id, $report->user_id, $report->currency, $items, (string) $report->tenant_id));
            }

            $payout->save();
            $this->recharge($report, $userId);

            $report->update(['status' => ReportStatus::Posted, 'posted_at' => now()]);
            $this->reports->audit($report, 'payout_exported', $userId, ReportStatus::Approved, ReportStatus::Posted, ['channel' => $channel->value, 'payout_id' => $payout->id, 'amount' => $amount]);

            event(new ExpenseReportPostedEvent((string) $report->id, $report->report_number, $report->currency, now()->toDateString(), $this->journal($report, $amount), (string) $report->tenant_id, $report->company_id));

            return $payout;
        });
    }

    public function confirmReimbursement(ExpenseReport $report, string $userId): ExpenseReport
    {
        $this->reports->assertCanMove($report, ReportStatus::Reimbursed);
        $report->update(['status' => ReportStatus::Reimbursed, 'reimbursed_at' => now()]);
        ExpensePayout::query()->where('expense_report_id', $report->id)->update(['status' => 'paid']);
        $this->reports->audit($report, 'reimbursed', $userId, ReportStatus::Posted, ReportStatus::Reimbursed);

        return $report;
    }

    /**
     * @return list<array{kind: string, amount: string, description: string}>
     */
    private function payrollItems(ExpenseReport $report, string $exempt, string $taxable): array
    {
        $items = [];

        if (Decimal::isPositive($exempt)) {
            $items[] = ['kind' => 'exempt', 'amount' => $exempt, 'description' => 'Expense reimbursement '.$report->report_number];
        }

        if (Decimal::isPositive($taxable)) {
            $items[] = ['kind' => 'taxable', 'amount' => $taxable, 'description' => 'Taxable expense reimbursement '.$report->report_number];
        }

        return $items;
    }

    private function recharge(ExpenseReport $report, string $userId): void
    {
        $billable = ExpenseLine::query()->where('expense_report_id', $report->id)->where('billable_to_customer', true)->whereNotNull('project_id')->get();

        foreach ($billable as $line) {
            $this->rebilling->recharge($line, $userId);
        }
    }

    /**
     * Balanced entry: the cost by category (net of the recoverable VAT), the recoverable VAT and the per-diem on the debit
     * side, the amount owed to the employee on the credit side. Card-paid lines were posted by the card reconciliation.
     *
     * @return list<array{account_code: string, debit: string, credit: string, description: string}>
     */
    private function journal(ExpenseReport $report, string $payable): array
    {
        $accounts = config('expense_management.accounts');
        $byAccount = [];
        $vat = '0.00';
        $debit = '0.00';

        $lines = ExpenseLine::query()->with('category')->where('expense_report_id', $report->id)->where('payment_method', PaymentMethod::EmployeeOutOfPocket->value)->where('is_reimbursable', true)->get();

        foreach ($lines as $line) {
            $code = $line->category?->default_gl_account_code ?: (string) $accounts['default_expense'];
            $net = Decimal::sub($line->amount_in_base_currency, $line->vat_recoverable_amount);
            $byAccount[$code] = Decimal::add($byAccount[$code] ?? '0.00', $net);
            $vat = Decimal::add($vat, $line->vat_recoverable_amount);
        }

        $perDiem = (string) $report->perDiems()->sum('gross_allowance_amount');

        if (Decimal::isPositive($perDiem)) {
            $byAccount[(string) $accounts['per_diem']] = Decimal::add($byAccount[(string) $accounts['per_diem']] ?? '0.00', Decimal::normalize($perDiem));
        }

        $journal = [];

        foreach ($byAccount as $code => $amount) {
            $journal[] = ['account_code' => (string) $code, 'debit' => $amount, 'credit' => '0.00', 'description' => $report->report_number];
            $debit = Decimal::add($debit, $amount);
        }

        if (Decimal::isPositive($vat)) {
            $journal[] = ['account_code' => (string) $accounts['recoverable_vat'], 'debit' => $vat, 'credit' => '0.00', 'description' => $report->report_number];
            $debit = Decimal::add($debit, $vat);
        }

        // The credit is what the employee is owed; a partial approval is carried on the difference line.
        $journal[] = ['account_code' => (string) $accounts['employee_payable'], 'debit' => '0.00', 'credit' => $debit, 'description' => $report->report_number];

        if (Decimal::cmp($debit, $payable) !== 0) {
            $journal[count($journal) - 1]['description'] = $report->report_number.' (approved '.$payable.')';
        }

        return $journal;
    }

    private function pain001(ExpenseReport $report, string $messageId, string $amount, string $executionDate): string
    {
        $iban = $report->employee_id === null ? null : PayrollEmployeeProfile::query()->where('employee_id', $report->employee_id)->value('iban');

        if ($iban === null || $iban === '') {
            throw new ExpenseException('employee_iban_missing');
        }

        $debtorAccount = BankAccount::query()->where('currency', $report->currency)->where('is_active', true)->orderByDesc('is_default')->first();

        if ($debtorAccount === null) {
            throw new ExpenseException('debtor_account_missing');
        }

        $tenant = Tenant::query()->find($report->tenant_id);
        $debtorName = $this->xml((string) ($tenant->legal_name ?? $tenant->name ?? 'Company'));
        $creditorName = $this->xml((string) (User::query()->whereKey($report->user_id)->value('name') ?? 'Employee'));
        $currency = $this->xml($report->currency);
        $iban = $this->xml(str_replace(' ', '', (string) $iban));
        $debtorIban = $this->xml(str_replace(' ', '', (string) $debtorAccount->iban));
        $reference = $this->xml('Expense reimbursement '.$report->report_number);
        $created = now()->toIso8601String();

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03">
  <CstmrCdtTrfInitn>
    <GrpHdr>
      <MsgId>{$messageId}</MsgId>
      <CreDtTm>{$created}</CreDtTm>
      <NbOfTxs>1</NbOfTxs>
      <CtrlSum>{$amount}</CtrlSum>
      <InitgPty><Nm>{$debtorName}</Nm></InitgPty>
    </GrpHdr>
    <PmtInf>
      <PmtInfId>{$messageId}-1</PmtInfId>
      <PmtMtd>TRF</PmtMtd>
      <NbOfTxs>1</NbOfTxs>
      <CtrlSum>{$amount}</CtrlSum>
      <ReqdExctnDt>{$executionDate}</ReqdExctnDt>
      <Dbtr><Nm>{$debtorName}</Nm></Dbtr>
      <DbtrAcct><Id><IBAN>{$debtorIban}</IBAN></Id></DbtrAcct>
      <DbtrAgt><FinInstnId><Othr><Id>NOTPROVIDED</Id></Othr></FinInstnId></DbtrAgt>
      <CdtTrfTxInf>
        <PmtId><EndToEndId>{$report->report_number}</EndToEndId></PmtId>
        <Amt><InstdAmt Ccy="{$currency}">{$amount}</InstdAmt></Amt>
        <Cdtr><Nm>{$creditorName}</Nm></Cdtr>
        <CdtrAcct><Id><IBAN>{$iban}</IBAN></Id></CdtrAcct>
        <RmtInf><Ustrd>{$reference}</Ustrd></RmtInf>
      </CdtTrfTxInf>
    </PmtInf>
  </CstmrCdtTrfInitn>
</Document>
XML;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
