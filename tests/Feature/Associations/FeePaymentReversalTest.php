<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Associations\Enums\FeeFrequency;
use Modules\Associations\Enums\InstalmentStatus;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\FeeInstalment;
use Modules\Associations\Models\FeePayment;
use Modules\Associations\Models\FeePlan;
use Modules\Associations\Models\Member;
use Modules\Associations\Services\FeeService;
use Modules\GeneralLedger\Models\CostCenter;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    actAs($this, 'secretary');
    Mail::fake();
});

afterEach(fn () => associationsTearDown());

function reversalInstalment(string $amount = '80.00', ?string $courseId = null): FeeInstalment
{
    $plan = FeePlan::factory()->create(['frequency' => FeeFrequency::OneOff, 'amount' => $amount]);

    return app(FeeService::class)->assign(Member::factory()->create(), $plan, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'course_id' => $courseId])->instalments->first();
}

function reversalAccountBalance(string $tenantId, string $code): float
{
    return round((float) DB::table('journal_lines')
        ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
        ->where('chart_of_accounts.tenant_id', $tenantId)
        ->where('chart_of_accounts.account_code', $code)
        ->selectRaw('COALESCE(SUM(journal_lines.debit_amount - journal_lines.credit_amount), 0) AS balance')
        ->value('balance'), 2);
}

test('a reversed payment cancels its receipt, reopens the instalment and reverses the accounts through a credit note outside SDI', function (): void {
    openFiscalPeriod($this->tenant);
    $instalment = reversalInstalment();
    $id = $this->postJson("/api/v1/associations/instalments/{$instalment->id}/payments", ['amount' => '80.00', 'paid_on' => now()->toDateString(), 'method' => 'bank_transfer'])
        ->assertCreated()->json('data.id');
    $payment = FeePayment::query()->findOrFail($id);
    $invoice = Invoice::query()->findOrFail($payment->invoice_id);

    expect($invoice->sdi_excluded)->toBeTrue();

    $this->postJson("/api/v1/associations/fee-payments/{$id}/reverse", ['reason' => 'Paid twice'])->assertOk()->assertJsonPath('data.reversal_reason', 'Paid twice');

    $payment->refresh();
    $creditNote = Invoice::query()->findOrFail($payment->credit_note_id);

    expect($payment->reversed_at)->not->toBeNull()
        ->and($payment->receipt->cancelled_at)->not->toBeNull()
        ->and($instalment->refresh()->status)->toBe(InstalmentStatus::Open)
        ->and((string) $instalment->paid_amount)->toBe('0.00')
        ->and(Payment::query()->find($payment->payment_id))->toBeNull()
        ->and($creditNote->invoice_type)->toBe('credit_note')
        ->and($creditNote->sdi_excluded)->toBeTrue()
        ->and($creditNote->invoice_number)->toBe('NC-'.$payment->receipt->number)
        ->and((string) $creditNote->total_gross)->toBe('80.00')
        ->and($creditNote->gl_journal_entry_id)->not->toBeNull()
        ->and($invoice->refresh()->payment_status)->toBe('paid')
        ->and(reversalAccountBalance($this->tenant->id, '1100'))->toBe(0.0)
        ->and(reversalAccountBalance($this->tenant->id, '1020'))->toBe(0.0);

    $this->postJson("/api/v1/associations/fee-payments/{$id}/reverse", ['reason' => 'Again'])->assertConflict();
    expect(Invoice::query()->where('invoice_type', 'credit_note')->count())->toBe(1);

    $this->postJson("/api/v1/associations/instalments/{$instalment->id}/payments", ['amount' => '80.00', 'paid_on' => now()->toDateString(), 'method' => 'cash'])->assertCreated();
    expect($instalment->refresh()->status)->toBe(InstalmentStatus::Paid);
});

test('a payment outside the accounts is reversed without a credit note', function (): void {
    AssociationSetting::current()->update(['ledger_enabled' => false]);
    $instalment = reversalInstalment('50.00');
    $this->postJson("/api/v1/associations/instalments/{$instalment->id}/payments", ['amount' => '20.00', 'paid_on' => now()->toDateString(), 'method' => 'cash'])->assertCreated();
    $id = $this->postJson("/api/v1/associations/instalments/{$instalment->id}/payments", ['amount' => '30.00', 'paid_on' => now()->toDateString(), 'method' => 'cash'])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/associations/fee-payments/{$id}/reverse", ['reason' => 'Wrong amount'])->assertOk();

    expect(FeePayment::query()->findOrFail($id)->credit_note_id)->toBeNull()
        ->and($instalment->refresh()->status)->toBe(InstalmentStatus::Partial)
        ->and((string) $instalment->paid_amount)->toBe('20.00')
        ->and(Invoice::query()->count())->toBe(0);
});

test('reversing needs the finance capability and stays inside the tenant', function (): void {
    AssociationSetting::current()->update(['ledger_enabled' => false]);
    $instalment = reversalInstalment('50.00');
    $id = $this->postJson("/api/v1/associations/instalments/{$instalment->id}/payments", ['amount' => '50.00', 'paid_on' => now()->toDateString(), 'method' => 'cash'])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/associations/fee-payments/{$id}/reverse", [])->assertUnprocessable();

    actAs($this, 'secretary', tenant: $this->otherTenant);
    $this->postJson("/api/v1/associations/fee-payments/{$id}/reverse", ['reason' => 'x'])->assertNotFound();
});

test('the cost centre of the course reaches the revenue line, and an invoice without one posts as before', function (): void {
    openFiscalPeriod($this->tenant);
    $centre = CostCenter::query()->create(['tenant_id' => $this->tenant->id, 'code' => 'NUOTO', 'name' => 'Swimming']);
    $course = Course::factory()->create(['cost_center_id' => $centre->id]);
    $withCentre = reversalInstalment('60.00', $course->id);
    $withoutCentre = reversalInstalment('40.00');

    $first = $this->postJson("/api/v1/associations/instalments/{$withCentre->id}/payments", ['amount' => '60.00', 'paid_on' => now()->toDateString(), 'method' => 'cash'])->assertCreated()->json('data.id');
    $second = $this->postJson("/api/v1/associations/instalments/{$withoutCentre->id}/payments", ['amount' => '40.00', 'paid_on' => now()->toDateString(), 'method' => 'cash'])->assertCreated()->json('data.id');

    $revenueCentre = fn (string $feePaymentId): array => DB::table('journal_lines')
        ->join('invoices', 'invoices.gl_journal_entry_id', '=', 'journal_lines.journal_entry_id')
        ->where('invoices.id', FeePayment::query()->findOrFail($feePaymentId)->invoice_id)
        ->where('journal_lines.credit_amount', '>', 0)
        ->pluck('journal_lines.cost_center_id')->unique()->values()->all();

    expect($revenueCentre($first))->toBe([$centre->id])
        ->and($revenueCentre($second))->toBe([null]);

    $this->postJson("/api/v1/associations/fee-payments/{$first}/reverse", ['reason' => 'Course cancelled'])->assertOk();

    $reversalCentres = DB::table('journal_lines')
        ->join('invoices', 'invoices.gl_journal_entry_id', '=', 'journal_lines.journal_entry_id')
        ->where('invoices.id', FeePayment::query()->findOrFail($first)->credit_note_id)
        ->where('journal_lines.debit_amount', '>', 0)
        ->pluck('journal_lines.cost_center_id')->unique()->values()->all();

    expect($reversalCentres)->toBe([$centre->id]);
});
