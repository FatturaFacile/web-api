<?php

declare(strict_types=1);

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\BillableActivity;
use App\Models\LegalCase;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\PracticeManagement\Jobs\ExpireFeeProposalsJob;
use Modules\PracticeManagement\Models\PracticeExpense;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-06-26 10:00:00');
    $this->tenant = verticalTenant();
    $this->client = verticalContact($this->tenant, 'Alfa Holding SA');
    $this->other = verticalContact($this->tenant, 'Beta Immobiliare SA');
    $this->partner = verticalMember($this->tenant, 'admin');

    app(TenantContext::class)->setTenant($this->tenant);
    $this->case = LegalCase::create(['client_id' => $this->client->id, 'case_number' => 'F-1', 'title' => 'Contract dispute', 'status' => 'open', 'jurisdiction' => 'CH']);
    $this->otherCase = LegalCase::create(['client_id' => $this->other->id, 'case_number' => 'F-2', 'title' => 'Lease', 'status' => 'open', 'jurisdiction' => 'CH']);
    $this->activity = BillableActivity::create(['legal_case_id' => $this->case->id, 'user_id' => $this->partner->id, 'date' => '2027-06-20', 'duration_minutes' => 120, 'hourly_rate' => '150', 'fee_amount' => '300.00', 'is_billed' => false]);

    verticalApi($this->partner, $this->tenant);
    $this->expense = fn (string $caseId, string $amount = '120.50', array $overrides = []) => $this->postJson('/api/v1/practice/expenses', [
        'legal_case_id' => $caseId, 'expense_date' => '2027-06-21', 'category' => 'court_fee', 'description' => 'Filing fee', 'amount' => $amount, ...$overrides,
    ]);
    $this->feeNote = fn (array $overrides = []) => $this->postJson('/api/v1/practice/fee-notes/from-activities', [
        'client_id' => $this->client->id, 'date' => '2027-06-26', 'cpa_rate' => '0', 'ritenuta_rate' => '0', 'vat_rate' => '10', ...$overrides,
    ]);
});

test('billable expenses are pulled into a fee note outside the taxable base and marked billed', function (): void {
    $first = ($this->expense)($this->case->id, '120.50')->assertCreated()->json('data.id');
    $second = ($this->expense)($this->case->id, '79.50')->assertCreated()->json('data.id');
    $notBillable = ($this->expense)($this->case->id, '10.00', ['is_billable' => false])->assertCreated()->json('data.id');
    $foreign = ($this->expense)($this->otherCase->id, '5.00')->assertCreated()->json('data.id');

    ($this->feeNote)([])->assertUnprocessable()->assertJsonValidationErrors(['activity_ids', 'expense_ids']);
    ($this->feeNote)(['expense_ids' => [$notBillable]])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'not billable'));
    ($this->feeNote)(['expense_ids' => [$foreign]])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'case of the client'));

    $note = ($this->feeNote)(['activity_ids' => [$this->activity->id], 'expense_ids' => [$first, $second]])->assertCreated()
        ->assertJsonPath('data.taxable_fees', '300.00')->assertJsonPath('data.vat_amount', '30.00')
        ->assertJsonPath('data.expenses_amount', '200.00')->assertJsonPath('data.net_payable', '530.00')->json('data');

    expect(PracticeExpense::query()->where('fee_note_id', $note['id'])->where('is_billed', true)->count())->toBe(2);
    ($this->feeNote)(['expense_ids' => [$first]])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'already billed'));
    $this->putJson("/api/v1/practice/expenses/{$first}", ['expense_date' => '2027-06-21', 'category' => 'court_fee', 'description' => 'Changed', 'amount' => '1.00'])->assertUnprocessable();

    $onlyExpense = ($this->expense)($this->case->id, '40.00')->assertCreated()->json('data.id');
    ($this->feeNote)(['expense_ids' => [$onlyExpense]])->assertCreated()->assertJsonPath('data.taxable_fees', '0.00')->assertJsonPath('data.net_payable', '40.00');
});

test('a receipt is attached to an expense, downloaded and removed', function (): void {
    $disk = (string) config('filesystems.private_disk', config('filesystems.default', 'local'));
    Storage::fake($disk);
    $id = ($this->expense)($this->case->id)->assertCreated()->assertJsonPath('data.has_receipt', false)->json('data.id');

    $this->postJson("/api/v1/practice/expenses/{$id}/receipt", ['receipt' => UploadedFile::fake()->create('notes.exe', 10)])->assertUnprocessable()->assertJsonValidationErrors('receipt');
    $this->getJson("/api/v1/practice/expenses/{$id}/receipt")->assertNotFound();

    $response = $this->postJson("/api/v1/practice/expenses/{$id}/receipt", ['receipt' => UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf')])->assertOk()->assertJsonPath('data.has_receipt', true);
    expect($response->json('data'))->not->toHaveKey('receipt_path');
    $this->get("/api/v1/practice/expenses/{$id}/receipt")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    $stored = PracticeExpense::query()->findOrFail($id)->receipt_path;
    Storage::disk($disk)->assertExists($stored);
    $this->deleteJson("/api/v1/practice/expenses/{$id}/receipt")->assertNoContent();
    Storage::disk($disk)->assertMissing($stored);
    $this->getJson("/api/v1/practice/expenses/{$id}/receipt")->assertNotFound();

    $outsider = verticalTenant();
    verticalApi(verticalMember($outsider, 'admin'), $outsider);
    $this->getJson("/api/v1/practice/expenses/{$id}/receipt")->assertNotFound();
});

test('a sent proposal past its validity date expires through the job and the endpoint, and it has a PDF', function (): void {
    $proposal = fn (string $validUntil) => $this->postJson('/api/v1/practice/fee-proposals', [
        'client_id' => $this->client->id, 'title' => 'Retainer', 'fee_type' => 'fixed', 'fixed_fee' => '2000.00', 'valid_until' => $validUntil,
    ])->assertCreated()->json('data.id');
    $send = fn (string $id) => $this->postJson("/api/v1/practice/fee-proposals/{$id}/send")->assertOk();
    $old = $proposal('2027-06-30');
    $future = $proposal('2027-12-31');
    $draft = $proposal('2027-06-30');
    $send($old);
    $send($future);

    $this->travelTo('2027-07-02 10:00:00');
    (new ExpireFeeProposalsJob($this->tenant->id))->handle(app(TenantContext::class));

    $this->getJson("/api/v1/practice/fee-proposals/{$old}")->assertOk()->assertJsonPath('data.status', 'expired');
    $this->getJson("/api/v1/practice/fee-proposals/{$future}")->assertJsonPath('data.status', 'sent');
    $this->getJson("/api/v1/practice/fee-proposals/{$draft}")->assertJsonPath('data.status', 'draft');
    $this->getJson('/api/v1/practice/fee-proposals?status=expired')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/practice/fee-proposals/{$old}/decline")->assertUnprocessable();

    $this->travelTo('2028-01-02 10:00:00');
    $this->postJson('/api/v1/practice/fee-proposals/expire')->assertOk()->assertJsonPath('data.expired', 1);
    $this->postJson('/api/v1/practice/fee-proposals/expire')->assertOk()->assertJsonPath('data.expired', 0);

    $pdf = $this->get("/api/v1/practice/fee-proposals/{$future}/pdf")->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')->and(str_starts_with($pdf->getContent(), '%PDF'))->toBeTrue();
});

test('client funds rows post to the general ledger through the contract and reconcile with the bank', function (): void {
    verticalLedger($this->tenant, 2027);
    $account = fn (string $code, string $name, string $type, string $normal): string => ChartOfAccount::query()->firstOrCreate(
        ['tenant_id' => $this->tenant->id, 'account_code' => $code],
        ['name' => $name, 'account_class' => $type, 'account_type' => $type, 'normal_balance' => $normal, 'currency' => 'CHF'],
    )->id;
    $accounts = ['bank_account_id' => $account('1020', 'Bank', 'asset', 'debit'), 'client_funds_account_id' => $account('2300', 'Client funds', 'liability', 'credit'), 'fee_revenue_account_id' => $account('3000', 'Revenue', 'revenue', 'credit')];
    $trust = fn (string $type, string $amount) => $this->postJson('/api/v1/practice/trust-ledger', ['type' => $type, 'client_id' => $this->client->id, 'occurred_on' => '2027-06-26', 'amount' => $amount])->assertCreated()->json('data.id');
    $deposit = $trust('deposit', '1000.00');
    $fees = $trust('fee_transfer', '300.00');

    $this->postJson("/api/v1/practice/trust-ledger/{$deposit}/post", ['client_funds_account_id' => $accounts['client_funds_account_id']])->assertUnprocessable()
        ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'bank account'));
    $this->postJson("/api/v1/practice/trust-ledger/{$deposit}/post", ['client_funds_account_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('client_funds_account_id');
    $posting = $this->postJson("/api/v1/practice/trust-ledger/{$deposit}/post", $accounts)->assertCreated()->json('data');
    $this->postJson("/api/v1/practice/trust-ledger/{$deposit}/post", $accounts)->assertUnprocessable();

    $entry = JournalEntry::withoutGlobalScopes()->findOrFail($posting['journal_entry_id']);
    expect($entry->lines()->withoutGlobalScopes()->where('account_id', $accounts['bank_account_id'])->value('debit_amount'))->toEqual(1000)
        ->and($entry->lines()->withoutGlobalScopes()->where('account_id', $accounts['client_funds_account_id'])->value('credit_amount'))->toEqual(1000);

    $this->postJson("/api/v1/practice/trust-ledger/{$fees}/post", ['client_funds_account_id' => $accounts['client_funds_account_id']])->assertUnprocessable();
    $this->postJson("/api/v1/practice/trust-ledger/{$fees}/post", $accounts)->assertCreated();

    $statement = BankStatement::create(['tenant_id' => $this->tenant->id, 'source_format' => 'camt053', 'account_iban' => 'CH9300762011623852957', 'statement_date' => '2027-06-26', 'currency' => 'CHF', 'total_entries' => 3, 'status' => 'draft']);
    $line = fn (int $number, string $amount, string $direction) => BankStatementLine::create(['tenant_id' => $this->tenant->id, 'bank_statement_id' => $statement->id, 'line_number' => $number, 'booking_date' => '2027-06-26', 'value_date' => '2027-06-26', 'amount' => $amount, 'direction' => $direction, 'description' => 'Transfer', 'reconciled' => false]);
    $wrongDirection = $line(1, '1000.00', 'debit');
    $wrongAmount = $line(2, '999.00', 'credit');
    $right = $line(3, '1000.00', 'credit');

    $this->postJson("/api/v1/practice/trust-ledger/{$fees}/reconcile", ['bank_statement_line_id' => $right->id])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'no money'));
    $this->postJson("/api/v1/practice/trust-ledger/{$deposit}/reconcile", ['bank_statement_line_id' => $wrongDirection->id])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'direction'));
    $this->postJson("/api/v1/practice/trust-ledger/{$deposit}/reconcile", ['bank_statement_line_id' => $wrongAmount->id])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'amount'));
    $this->postJson("/api/v1/practice/trust-ledger/{$deposit}/reconcile", ['bank_statement_line_id' => $right->id])->assertOk()->assertJsonPath('data.bank_statement_line_id', $right->id);
    expect($right->refresh()->reconciled)->toBeTrue()->and($right->journal_entry_id)->toBe($posting['journal_entry_id']);
    $this->postJson("/api/v1/practice/trust-ledger/{$deposit}/reconcile", ['bank_statement_line_id' => $wrongAmount->id])->assertUnprocessable();

    $withdrawal = $trust('withdrawal', '100.00');
    $this->postJson("/api/v1/practice/trust-ledger/{$withdrawal}/reconcile", ['bank_statement_line_id' => $wrongAmount->id])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'Post the client funds row'));

    $reversal = $this->postJson("/api/v1/practice/trust-ledger/{$withdrawal}/reverse")->assertCreated()->json('data.id');
    $this->postJson("/api/v1/practice/trust-ledger/{$reversal}/post", $accounts)->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'not posted'));
    $this->postJson("/api/v1/practice/trust-ledger/{$withdrawal}/post", $accounts)->assertCreated();
    $this->postJson("/api/v1/practice/trust-ledger/{$reversal}/post", $accounts)->assertCreated();
    expect(JournalEntry::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(4);
    $this->getJson('/api/v1/practice/trust-ledger')->assertOk()->assertJsonPath('data.0.posting.trust_transaction_id', $reversal);
});
