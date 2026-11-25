<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\ExpenseManagement\Events\ExpensePayrollItemsReadyEvent;
use Modules\ExpenseManagement\Events\ExpenseReportApprovedEvent;
use Modules\ExpenseManagement\Events\ExpenseReportPostedEvent;
use Modules\ExpenseManagement\Models\ExpenseAuditLog;

require_once __DIR__.'/ExpenseManagementSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    exmSetUp($this);
    Storage::fake('local');
});

/**
 * A receipt (a unique text file) read by the OCR.
 */
function exmReceipt(object $test): string
{
    $text = 'Negozio '.Str::random(8)."\n".now()->subDays(2)->format('d/m/Y')."\nTOTALE EUR 40,00\n";

    return (string) $test->postJson('/api/v1/expense-management/receipts/scan', ['file' => UploadedFile::fake()->createWithContent('r.txt', $text)])->assertOk()->json('data.receipt_id');
}

/**
 * A submitted report: one documented, traceable line of 40.00 (VAT 10 included).
 */
function exmSubmittedReport(object $test): string
{
    $report = exmCreateReport($test);
    exmAddLine($test, $report, 'supplies', ['merchant_name' => 'Cartoleria '.Str::random(5), 'receipt_id' => exmReceipt($test), 'merchant_tax_id' => 'IT01234567890', 'vat_rate' => '22'])->assertCreated();
    $test->postJson("/api/v1/expense-management/reports/{$report}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');

    return $report;
}

it('refuses to submit an empty report or one with a blocking violation', function (): void {
    $report = exmCreateReport($this);
    $this->postJson("/api/v1/expense-management/reports/{$report}/submit")->assertStatus(422)->assertJsonPath('code', 'report_empty');

    exmAddLine($this, $report, 'supplies')->assertCreated(); // no receipt above the zero threshold: blocking
    $this->postJson("/api/v1/expense-management/reports/{$report}/submit")->assertStatus(422)->assertJsonPath('code', 'blocking_violations');
});

it('takes a report from submission to reimbursement through payroll, with the ledger and payroll hooks', function (): void {
    Event::fake([ExpenseReportApprovedEvent::class, ExpenseReportPostedEvent::class, ExpensePayrollItemsReadyEvent::class]);
    $report = exmSubmittedReport($this);

    // The claimant cannot approve, a plain member cannot either.
    $this->postJson("/api/v1/expense-management/reports/{$report}/approve")->assertForbidden();

    exmActAs($this, 'accountant');
    $this->postJson("/api/v1/expense-management/reports/{$report}/start-review")->assertOk()->assertJsonPath('data.status', 'under_review');
    $this->postJson("/api/v1/expense-management/reports/{$report}/approve", ['approved_amount' => '999'])->assertStatus(422)->assertJsonPath('code', 'approved_amount_exceeds');
    $this->postJson("/api/v1/expense-management/reports/{$report}/approve", ['comment' => 'ok'])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_amount', '40.00');
    Event::assertDispatched(ExpenseReportApprovedEvent::class);

    $this->postJson("/api/v1/expense-management/reports/{$report}/export-payout", ['channel' => 'payroll'])->assertOk()->assertJsonPath('data.exempt_amount', '40.00');
    Event::assertDispatched(ExpensePayrollItemsReadyEvent::class, fn (ExpensePayrollItemsReadyEvent $event): bool => $event->items[0]['kind'] === 'exempt');
    Event::assertDispatched(ExpenseReportPostedEvent::class, function (ExpenseReportPostedEvent $event): bool {
        $debit = collect($event->journalLines)->sum(fn (array $line): float => (float) $line['debit']);
        $credit = collect($event->journalLines)->sum(fn (array $line): float => (float) $line['credit']);

        return abs($debit - $credit) < 0.001 && abs($debit - 40.0) < 0.001;
    });

    $this->postJson("/api/v1/expense-management/reports/{$report}/export-payout", ['channel' => 'payroll'])->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
    $this->postJson("/api/v1/expense-management/reports/{$report}/confirm-reimbursement")->assertOk()->assertJsonPath('data.status', 'reimbursed');

    expect(ExpenseAuditLog::query()->where('expense_report_id', $report)->orderBy('created_at')->pluck('action')->all())
        ->toContain('submitted', 'review_started', 'approved', 'payout_exported', 'reimbursed');
});

it('rejects a report with a reason, lets the employee fix and resubmit it', function (): void {
    $report = exmSubmittedReport($this);

    exmActAs($this, 'admin');
    $this->postJson("/api/v1/expense-management/reports/{$report}/reject")->assertStatus(422);
    $this->postJson("/api/v1/expense-management/reports/{$report}/reject", ['reason' => 'Wrong cost centre'])->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Wrong cost centre');
});

it('reserves the payout to the finance roles and needs the IBAN of the employee for a pain.001 file', function (): void {
    $report = exmSubmittedReport($this);
    exmActAs($this, 'hr');
    $this->postJson("/api/v1/expense-management/reports/{$report}/approve")->assertOk();
    $this->postJson("/api/v1/expense-management/reports/{$report}/export-payout", ['channel' => 'pain001'])->assertForbidden();

    exmActAs($this, 'accountant');
    $this->postJson("/api/v1/expense-management/reports/{$report}/export-payout", ['channel' => 'pain001'])->assertStatus(422)->assertJsonPath('code', 'employee_iban_missing');
});

it('cancels a draft report and refuses to cancel a paid one', function (): void {
    $draft = exmCreateReport($this);
    $this->deleteJson("/api/v1/expense-management/reports/{$draft}")->assertOk();
    $this->getJson("/api/v1/expense-management/reports/{$draft}")->assertNotFound();

    $submitted = exmSubmittedReport($this);
    $claimant = $this->employee;
    exmActAs($this, 'accountant');
    $this->postJson("/api/v1/expense-management/reports/{$submitted}/approve")->assertOk();
    $this->postJson("/api/v1/expense-management/reports/{$submitted}/export-payout", ['channel' => 'payroll'])->assertOk();
    treasuryActAs($this, $claimant);
    $this->postJson("/api/v1/expense-management/reports/{$submitted}/cancel")->assertStatus(409);
});
