<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\AccountingTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Enums\PostingAccountRole;
use Modules\GeneralLedger\Models\AccountingTemplate;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\DefaultPostingAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;

uses(RefreshDatabase::class);

function plMember(Tenant $tenant): User
{
    $user = User::create(['name' => 'accountant', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'accountant']);

    return $user;
}

function plActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

function plAccount(Tenant $tenant, string $code, string $type, string $normal): ChartOfAccount
{
    return ChartOfAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'company_id' => null,
        'account_code' => $code,
        'account_type' => $type,
        'account_class' => $type,
        'normal_balance' => $normal,
    ]);
}

function plPost(string $date, ChartOfAccount $debit, ChartOfAccount $credit, string $amount, string $ledgerType = 'statutory'): JournalEntry
{
    $period = FiscalPeriod::withoutGlobalScopes()->where('tenant_id', test()->tenant->id)->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->firstOrFail();

    return app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => test()->tenant->id,
        'fiscal_period_id' => $period->id,
        'posting_date' => $date,
        'description' => 'Test entry',
        'ledger_type' => $ledgerType,
        'bank_balance_managed' => true,
        'lines' => [
            ['account_id' => $debit->id, 'debit_amount' => $amount, 'credit_amount' => 0],
            ['account_id' => $credit->id, 'debit_amount' => 0, 'credit_amount' => $amount],
        ],
    ]);
}

/**
 * The global system templates are written outside any tenant, as the seeder does.
 */
function plSeedTemplates(): void
{
    $context = app(TenantContext::class);
    $tenant = $context->getTenant();
    $context->clear();
    (new AccountingTemplateSeeder)->run();
    $context->setTenant($tenant);
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA', 'default_currency' => 'EUR']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->accountant = plMember($this->tenant);
    $this->outsider = plMember($this->other);

    app(TenantContext::class)->setTenant($this->tenant);
    $this->year2025 = app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2025);
    $this->year2026 = app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2026);

    $this->bank = plAccount($this->tenant, '1000', 'asset', 'debit');
    $this->receivable = plAccount($this->tenant, '1100', 'asset', 'debit');
    $this->vatIn = plAccount($this->tenant, '1170', 'asset', 'debit');
    $this->payable = plAccount($this->tenant, '2000', 'liability', 'credit');
    $this->vatOut = plAccount($this->tenant, '2200', 'liability', 'credit');
    $this->capital = plAccount($this->tenant, '3000', 'equity', 'credit');
    $this->sales = plAccount($this->tenant, '4000', 'revenue', 'credit');
    $this->rent = plAccount($this->tenant, '6000', 'expense', 'debit');
    DefaultPostingAccount::query()->create(['tenant_id' => $this->tenant->id, 'role' => PostingAccountRole::RetainedEarnings->value, 'account_id' => $this->capital->id]);
});

afterEach(fn () => app(TenantContext::class)->clear());

// ---------------------------------------------------------------- parallel ledger books

test('the trial balance reads one ledger book at a time: 01 statutory by default, 02 tax, 03 ifrs', function (): void {
    plPost('2026-02-10', $this->bank, $this->capital, '1000.00');
    plPost('2026-02-11', $this->bank, $this->capital, '400.00', 'tax');
    plPost('2026-02-12', $this->bank, $this->capital, '250.00', 'ifrs');

    plActAs($this->accountant, $this->tenant);
    $query = 'from=2026-01-01&to=2026-12-31';
    $bankRow = fn (array $data): array => collect($data['accounts'])->firstWhere('account_code', '1000');

    expect($bankRow($this->getJson("/api/v1/general-ledger/reports/trial-balance?{$query}")->assertOk()->json('data'))['debit'])->toBe('1000.0000')
        ->and($bankRow($this->getJson("/api/v1/general-ledger/reports/trial-balance?{$query}&ledger=01")->json('data'))['debit'])->toBe('1000.0000')
        ->and($bankRow($this->getJson("/api/v1/general-ledger/reports/trial-balance?{$query}&ledger=02")->json('data'))['debit'])->toBe('400.0000')
        ->and($bankRow($this->getJson("/api/v1/general-ledger/reports/trial-balance?{$query}&ledger=03")->json('data'))['debit'])->toBe('250.0000');

    $this->getJson("/api/v1/general-ledger/reports/trial-balance?{$query}&ledger=04")->assertUnprocessable();
});

test('the trial balance has a 4-column and a 2-column layout that both balance', function (): void {
    plPost('2026-02-10', $this->bank, $this->capital, '1000.00');
    plPost('2026-03-10', $this->rent, $this->bank, '300.00');

    plActAs($this->accountant, $this->tenant);

    $four = $this->getJson('/api/v1/general-ledger/reports/trial-balance?from=2026-03-01&to=2026-03-31')->assertOk()->json('data');
    $bank = collect($four['accounts'])->firstWhere('account_code', '1000');

    expect($bank['opening_balance'])->toBe('1000.0000')
        ->and($bank['credit'])->toBe('300.0000')
        ->and($bank['closing_balance'])->toBe('700.0000')
        ->and($four['is_balanced'])->toBeTrue();

    $two = $this->getJson('/api/v1/general-ledger/reports/trial-balance?from=2026-01-01&to=2026-12-31&columns=2')->assertOk()->json('data');
    $capital = collect($two['accounts'])->firstWhere('account_code', '3000');

    expect($capital['credit_balance'])->toBe('1000.0000')
        ->and($capital['debit_balance'])->toBe('0.0000')
        ->and($two['totals']['debit_balance'])->toBe($two['totals']['credit_balance'])
        ->and($two['is_balanced'])->toBeTrue();
});

test('the account ledger (mastrino) is read per ledger book with a running balance', function (): void {
    plPost('2026-02-10', $this->bank, $this->capital, '1000.00');
    plPost('2026-02-11', $this->bank, $this->capital, '400.00', 'tax');
    plPost('2026-02-12', $this->bank, $this->capital, '50.00', 'tax');

    plActAs($this->accountant, $this->tenant);
    $data = $this->getJson("/api/v1/general-ledger/reports/account-ledger?account_id={$this->bank->id}&from=2026-01-01&to=2026-12-31&ledger=02")->assertOk()->json('data');

    expect($data['lines'])->toHaveCount(2)
        ->and($data['lines'][1]['running_balance'])->toBe('450.0000')
        ->and($data['closing_balance'])->toBe('450.0000');
});

test('a journal can be posted to several books at once, each its own balanced entry', function (): void {
    plActAs($this->accountant, $this->tenant);

    $response = $this->postJson('/api/v1/general-ledger/journals', [
        'posting_date' => '2026-04-10',
        'description' => 'Depreciation adjustment',
        'post_to_ledgers' => ['02', '03'],
        'lines' => [
            ['account_id' => $this->rent->id, 'debit_amount' => 120, 'credit_amount' => 0],
            ['account_id' => $this->bank->id, 'debit_amount' => 0, 'credit_amount' => 120],
        ],
    ])->assertCreated();

    $entries = JournalEntry::query()->where('description', 'Depreciation adjustment')->orderBy('ledger_type')->get();

    expect($entries->pluck('ledger_type')->all())->toBe(['ifrs', 'statutory', 'tax'])
        ->and($entries->every(fn (JournalEntry $entry): bool => $entry->isBalanced() && $entry->status === 'posted'))->toBeTrue()
        ->and($entries->pluck('entry_number')->sort()->values()->all())->toBe(collect([
            $response->json('data.entry_number'),
            $response->json('data.entry_number').'-L02',
            $response->json('data.entry_number').'-L03',
        ])->sort()->values()->all());
});

test('an unbalanced entry is rejected and nothing is written', function (): void {
    plActAs($this->accountant, $this->tenant);

    $this->postJson('/api/v1/general-ledger/journals', [
        'posting_date' => '2026-04-10',
        'description' => 'Unbalanced',
        'lines' => [
            ['account_id' => $this->rent->id, 'debit_amount' => 100, 'credit_amount' => 0],
            ['account_id' => $this->bank->id, 'debit_amount' => 0, 'credit_amount' => 99],
        ],
    ])->assertStatus(422);

    expect(JournalEntry::query()->where('description', 'Unbalanced')->count())->toBe(0);
});

test('a posted entry is immutable and a storno reversal keeps its book and the original untouched', function (): void {
    $entry = plPost('2026-05-10', $this->rent, $this->bank, '80.00', 'tax');

    expect(fn () => $entry->update(['description' => 'Tampered']))->toThrow('immutable');
    expect(fn () => $entry->lines()->first()->update(['debit_amount' => '1.00']))->toThrow('immutable');

    plActAs($this->accountant, $this->tenant);
    $this->postJson("/api/v1/general-ledger/journals/{$entry->id}/reverse", ['reversal_date' => '2026-05-20', 'reason' => 'Wrong account'])->assertSuccessful();

    $original = JournalEntry::query()->findOrFail($entry->id);
    $reversal = JournalEntry::query()->findOrFail($original->reversal_journal_entry_id);

    expect($original->status)->toBe('reversed')
        ->and($original->description)->toBe('Test entry')
        ->and($reversal->ledger_type)->toBe('tax')
        ->and($reversal->lines->firstWhere('account_id', $this->rent->id)->credit_amount)->toEqual('80.0000');

    $balance = $this->getJson('/api/v1/general-ledger/reports/trial-balance?from=2026-01-01&to=2026-12-31&ledger=02')->assertOk()->json('data');
    expect(collect($balance['accounts'])->firstWhere('account_code', '6000')['closing_balance'])->toBe('0.0000');
});

// ---------------------------------------------------------------- accounting templates (causali)

test('the system templates cover payroll, interest, self-invoices, advances and VAT in four languages', function (): void {
    plSeedTemplates();
    plSeedTemplates();

    expect(AccountingTemplate::withoutGlobalScopes()->whereNull('tenant_id')->count())->toBe(8);

    plActAs($this->accountant, $this->tenant);
    $list = $this->getJson('/api/v1/general-ledger/accounting-templates')->assertOk()->json('data');
    $codes = collect($list)->pluck('code')->all();

    expect($codes)->toContain('PAYROLL_ALLOCATION', 'INTEREST_INCOME', 'INTEREST_EXPENSE', 'SELF_INVOICE', 'ADVANCE_REVERSAL', 'EXPENSE_WITH_VAT');

    foreach ($list as $template) {
        expect(array_keys($template['names']))->toEqualCanonicalizing(['it', 'de', 'fr', 'en']);
    }

    $payroll = collect($list)->firstWhere('code', 'PAYROLL_ALLOCATION');
    expect($payroll['names']['it'])->toBe('Giroconto stipendi')
        ->and($payroll['names']['de'])->toBe('Lohnbuchung');
});

test('a template previews the lines it yields from the amount, the rate and the accounts', function (): void {
    plSeedTemplates();
    $template = AccountingTemplate::withoutGlobalScopes()->where('code', 'EXPENSE_WITH_VAT')->firstOrFail();

    plActAs($this->accountant, $this->tenant);
    $preview = $this->postJson("/api/v1/general-ledger/accounting-templates/{$template->id}/preview", [
        'amount' => '100.00',
        'tax_rate' => '8.1',
        'accounts' => ['expense' => '6000', 'input_vat' => '1170', 'payable' => $this->payable->id],
    ])->assertOk()->json('data');

    expect($preview['lines'])->toHaveCount(3)
        ->and($preview['total_debit'])->toBe('108.1000')
        ->and($preview['total_credit'])->toBe('108.1000')
        ->and($preview['is_balanced'])->toBeTrue()
        ->and($preview['lines'][2]['credit_amount'])->toBe('108.1000');
    expect(JournalEntry::query()->count())->toBe(0);
});

test('a template needs every account and the rate it taxes with, and only accounts of the tenant', function (): void {
    plSeedTemplates();
    $template = AccountingTemplate::withoutGlobalScopes()->where('code', 'SELF_INVOICE')->firstOrFail();
    app(TenantContext::class)->setTenant($this->other);
    $foreign = plAccount($this->other, '9999', 'expense', 'debit');
    app(TenantContext::class)->setTenant($this->tenant);
    expect((string) $foreign->tenant_id)->toBe((string) $this->other->id);

    plActAs($this->accountant, $this->tenant);
    $url = "/api/v1/general-ledger/accounting-templates/{$template->id}/preview";

    $this->postJson($url, ['amount' => '100', 'accounts' => ['expense' => '6000']])->assertUnprocessable()->assertJsonValidationErrors(['tax_rate']);
    $this->postJson($url, ['amount' => '100', 'tax_rate' => '8.1', 'accounts' => ['expense' => (string) $foreign->id, 'input_vat' => '1170', 'output_vat' => '2200', 'counterparty' => '2000']])
        ->assertUnprocessable()->assertJsonValidationErrors(['accounts.expense']);
});

test('a template is posted to the prima nota and to the other books on request', function (): void {
    plSeedTemplates();
    $template = AccountingTemplate::withoutGlobalScopes()->where('code', 'SELF_INVOICE')->firstOrFail();

    plActAs($this->accountant, $this->tenant);
    $response = $this->postJson("/api/v1/general-ledger/accounting-templates/{$template->id}/post", [
        'amount' => '200.00',
        'tax_rate' => '8.1',
        'posting_date' => '2026-06-15',
        'post_to_ledgers' => ['02'],
        'accounts' => ['expense' => '6000', 'input_vat' => '1170', 'output_vat' => '2200', 'counterparty' => '2000'],
    ])->assertCreated();

    $entries = JournalEntry::query()->where('source_document_type', 'accounting_template')->orderBy('ledger_type')->get();

    expect($response->json('data.status'))->toBe('posted')
        ->and($entries->pluck('ledger_type')->all())->toBe(['statutory', 'tax'])
        ->and($entries->every(fn (JournalEntry $entry): bool => $entry->isBalanced() && $entry->lines()->count() === 4))->toBeTrue()
        ->and((string) $entries->first()->total_amount)->toBe('216.2000');
});

test('a tenant keeps its own templates, cannot change a system one and never sees another tenant template', function (): void {
    plSeedTemplates();
    $system = AccountingTemplate::withoutGlobalScopes()->where('code', 'PAYROLL_ALLOCATION')->firstOrFail();
    $payload = [
        'code' => 'BANK_FEES',
        'category' => 'expense',
        'names' => ['it' => 'Spese bancarie', 'de' => 'Bankspesen', 'fr' => 'Frais bancaires', 'en' => 'Bank fees'],
        'parameters' => [['key' => 'amount', 'type' => 'amount'], ['key' => 'fees', 'type' => 'account'], ['key' => 'bank', 'type' => 'account']],
        'lines' => [['account' => '$fees', 'side' => 'debit', 'amount' => 'amount'], ['account' => '$bank', 'side' => 'credit', 'amount' => 'amount']],
    ];

    plActAs($this->accountant, $this->tenant);
    $id = $this->postJson('/api/v1/general-ledger/accounting-templates', $payload)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/general-ledger/accounting-templates', $payload)->assertUnprocessable();
    $this->putJson("/api/v1/general-ledger/accounting-templates/{$id}", ['names' => $payload['names']])->assertOk();
    $this->putJson("/api/v1/general-ledger/accounting-templates/{$system->id}", ['names' => $payload['names']])->assertForbidden();
    $this->deleteJson("/api/v1/general-ledger/accounting-templates/{$system->id}")->assertForbidden();

    plActAs($this->outsider, $this->other);
    $codes = collect($this->getJson('/api/v1/general-ledger/accounting-templates')->assertOk()->json('data'))->pluck('code');

    expect($codes)->toContain('PAYROLL_ALLOCATION')->not->toContain('BANK_FEES');
    $this->getJson("/api/v1/general-ledger/accounting-templates/{$id}")->assertNotFound();

    plActAs($this->accountant, $this->tenant);
    $this->deleteJson("/api/v1/general-ledger/accounting-templates/{$id}")->assertOk();
});

// ---------------------------------------------------------------- year-end workflow

function plSeedYear(): void
{
    $t = test();
    plPost('2025-01-10', $t->bank, $t->capital, '10000.00');
    plPost('2025-02-10', $t->receivable, $t->sales, '4000.00');
    plPost('2025-03-10', $t->rent, $t->payable, '1500.00');
    plPost('2025-01-10', $t->bank, $t->capital, '5000.00', 'tax');
    plPost('2025-02-10', $t->receivable, $t->sales, '800.00', 'tax');
}

test('the year-end workflow runs the four stages in order and never twice', function (): void {
    plSeedYear();
    plActAs($this->accountant, $this->tenant);
    $base = "/api/v1/general-ledger/fiscal-years/{$this->year2025->id}/closing-workflow";

    $status = $this->getJson($base)->assertOk()->json('data');
    expect($status['next_stage'])->toBe(1)
        ->and(collect($status['stages'])->pluck('status')->all())->toBe(['ready', 'blocked', 'blocked', 'blocked']);

    $this->postJson("{$base}/stages/3")->assertConflict();
    $this->postJson("{$base}/stages/5")->assertNotFound();

    $one = $this->postJson("{$base}/stages/1")->assertOk()->json('data');
    expect($one['already_completed'])->toBeFalse()
        ->and($one['stage']['summary']['periods_closed'])->toBe(12);

    $two = $this->postJson("{$base}/stages/2")->assertOk()->json('data');
    expect($two['stage']['summary']['net_result'])->toBe('2500.0000')
        ->and($two['stage']['summary']['result'])->toBe('profit')
        ->and(JournalEntry::query()->where('journal_type', 'closing')->count())->toBe(0);

    $three = $this->postJson("{$base}/stages/3")->assertOk()->json('data');
    $closing = JournalEntry::query()->findOrFail($three['stage']['journal_entry_id']);
    expect($closing->journal_type)->toBe('closing')->and($closing->isBalanced())->toBeTrue()
        ->and($this->year2025->fresh()->status)->toBe('closed');

    $four = $this->postJson("{$base}/stages/4")->assertOk()->json('data');
    $opening = JournalEntry::query()->findOrFail($four['stage']['journal_entry_id']);
    expect($opening->journal_type)->toBe('opening')
        ->and($opening->posting_date->toDateString())->toBe('2026-01-01')
        ->and($opening->ledger_type)->toBe('statutory');

    $again = $this->postJson("{$base}/stages/3")->assertOk()->json('data');
    $this->postJson("{$base}/stages/4")->assertOk();

    expect($again['already_completed'])->toBeTrue()
        ->and(JournalEntry::query()->where('journal_type', 'closing')->where('ledger_type', 'statutory')->count())->toBe(1)
        ->and(JournalEntry::query()->where('journal_type', 'opening')->where('ledger_type', 'statutory')->count())->toBe(1)
        ->and($this->getJson($base)->json('data.is_complete'))->toBeTrue();
});

test('the tax book closes and opens on its own, leaving the statutory book alone', function (): void {
    plSeedYear();
    plActAs($this->accountant, $this->tenant);
    $base = "/api/v1/general-ledger/fiscal-years/{$this->year2025->id}/closing-workflow";

    foreach ([1, 2, 3, 4] as $stage) {
        $this->postJson("{$base}/stages/{$stage}", ['ledger' => '02'])->assertOk();
    }

    $closing = JournalEntry::query()->where('ledger_type', 'tax')->where('journal_type', 'closing')->firstOrFail();
    $opening = JournalEntry::query()->where('ledger_type', 'tax')->where('journal_type', 'opening')->firstOrFail();
    $lines = $opening->lines->keyBy('account_id');

    expect($closing->isBalanced())->toBeTrue()
        ->and($closing->entry_number)->toBe('YE-CLOSE-FY2025-TAX')
        ->and($opening->entry_number)->toBe('OPEN-FY2026-TAX')
        ->and((string) $lines[$this->bank->id]->debit_amount)->toBe('5000.0000')
        ->and((string) $lines[$this->receivable->id]->debit_amount)->toBe('800.0000')
        ->and((string) $lines[$this->capital->id]->credit_amount)->toBe('5800.0000')
        ->and(JournalEntry::query()->where('ledger_type', 'statutory')->whereIn('journal_type', ['closing', 'opening'])->count())->toBe(0)
        ->and($this->getJson("{$base}?ledger=02")->json('data.is_complete'))->toBeTrue()
        ->and($this->getJson("{$base}?ledger=01")->json('data.next_stage'))->toBe(1);

    $this->postJson("{$base}/stages/4", ['ledger' => '02'])->assertOk();
    expect(JournalEntry::query()->where('ledger_type', 'tax')->where('journal_type', 'opening')->count())->toBe(1);

    $balance = $this->getJson('/api/v1/general-ledger/reports/trial-balance?from=2026-01-01&to=2026-12-31&ledger=02')->assertOk()->json('data');
    expect($balance['is_balanced'])->toBeTrue();
});

test('the workflow of another tenant fiscal year is not reachable', function (): void {
    plActAs($this->outsider, $this->other);

    $this->getJson("/api/v1/general-ledger/fiscal-years/{$this->year2025->id}/closing-workflow")->assertNotFound();
    $this->postJson("/api/v1/general-ledger/fiscal-years/{$this->year2025->id}/closing-workflow/stages/1")->assertNotFound();
});
