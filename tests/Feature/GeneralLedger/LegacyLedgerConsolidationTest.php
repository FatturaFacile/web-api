<?php

declare(strict_types=1);

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\ChartOfAccount as LegacyAccount;
use App\Models\Contact;
use App\Models\GeneralLedgerEntry;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\LegacyLedgerBridge;
use App\Services\Accounting\LegacyLedgerMigrator;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Contracts\PeriodClosingServiceInterface;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;

uses(RefreshDatabase::class);

/**
 * One ledger: the legacy accounting endpoints, the legacy ledger rows and the payment / reconciliation postings all end
 * in the journal of the General Ledger module, and the legacy readers show its figures.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Libro Unico Srl', 'legal_name' => 'Libro Unico Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->year = app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2026);

    // Accounts written by the legacy model (code / type only): the module columns are filled on save.
    $this->cash = LegacyAccount::create(['tenant_id' => $this->tenant->id, 'code' => '1000', 'name' => 'Cassa', 'type' => 'asset']);
    $this->revenue = LegacyAccount::create(['tenant_id' => $this->tenant->id, 'code' => '4000', 'name' => 'Ricavi', 'type' => 'revenue']);

    $this->user = User::create(['name' => 'Contabile', 'email' => Str::random(10).'@example.it', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'accountant']);
    Sanctum::actingAs($this->user);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
});

/**
 * @return array<string, mixed>
 */
function legacyLedgerPayload(string $number, string $date, string $amount): array
{
    return [
        'entry_number' => $number,
        'date' => $date,
        'description' => "Incasso {$number}",
        'lines' => [
            ['account_id' => test()->cash->id, 'debit' => $amount, 'credit' => 0],
            ['account_id' => test()->revenue->id, 'debit' => 0, 'credit' => $amount, 'description' => 'Ricavo'],
        ],
    ];
}

function legacyLedgerPeriod(string $date): FiscalPeriod
{
    return FiscalPeriod::withoutGlobalScopes()->where('tenant_id', test()->tenant->id)->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->firstOrFail();
}

/**
 * A row of the legacy ledger, as the legacy endpoint used to write it.
 *
 * @param  array<string, mixed>  $overrides
 */
function legacyLedgerRow(string $number, string $date, string $debit, string $credit, array $overrides = []): GeneralLedgerEntry
{
    $entry = GeneralLedgerEntry::create(array_merge(['tenant_id' => test()->tenant->id, 'entry_number' => $number, 'date' => $date, 'description' => "Legacy {$number}"], $overrides));
    $entry->lines()->create(['account_id' => test()->cash->id, 'line_number' => 1, 'debit' => $debit, 'credit' => 0]);
    $entry->lines()->create(['account_id' => test()->revenue->id, 'line_number' => 2, 'debit' => 0, 'credit' => $credit]);

    return $entry;
}

test('accounts written by the legacy model carry the code, type and normal balance the ledger reads', function (): void {
    $revenue = DB::table('chart_of_accounts')->where('id', $this->revenue->id)->first();

    expect($revenue->account_code)->toBe('4000')
        ->and($revenue->account_type)->toBe('revenue')
        ->and($revenue->normal_balance)->toBe('credit')
        ->and(DB::table('chart_of_accounts')->where('id', $this->cash->id)->value('normal_balance'))->toBe('debit');
});

test('a legacy post lands in the module journal and in its trial balance, flagged as deprecated', function (): void {
    $response = $this->postJson('/api/v1/accounting/ledger-entries', legacyLedgerPayload('GL-2026-0001', '2026-03-10', '150.50'))
        ->assertCreated()
        ->assertHeader('X-Deprecated', 'true')
        ->assertJsonPath('data.balanced_amount', 150.5)
        ->assertJsonPath('data.journal_entry.entry_number', 'GL-2026-0001')
        ->assertJsonPath('data.journal_entry.status', 'posted')
        ->assertJsonPath('data.journal_entry.journal_number', 1)
        ->assertJsonPath('data.journal_entry.posting_date', '2026-03-10');

    expect($response->headers->get('Link'))->toContain('/api/v1/general-ledger/journals');

    $entry = JournalEntry::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    $period = legacyLedgerPeriod('2026-03-10');

    expect((string) $entry->fiscal_period_id)->toBe((string) $period->id)
        ->and($entry->source_document_type)->toBe(LegacyLedgerBridge::SOURCE_LEGACY_API)
        ->and($response->json('data.journal_entry.id'))->toBe((string) $entry->id)
        ->and(DB::table('general_ledger_entries')->count())->toBe(0);

    $trialBalance = $this->getJson("/api/v1/general-ledger/financial-statements/trial-balance?fiscal_period_id={$period->id}")->assertOk();
    $accounts = collect($trialBalance->json('data.accounts'))->keyBy('account_code');

    expect($accounts['1000']['debit'])->toEqual(150.5)
        ->and($accounts['4000']['credit'])->toEqual(150.5)
        ->and($accounts['4000']['net_balance'])->toEqual(150.5)
        ->and($trialBalance->json('data.is_balanced'))->toBeTrue();
});

test('a closed period refuses the legacy post and nothing is written', function (): void {
    app(PeriodClosingServiceInterface::class)->closePeriod((string) legacyLedgerPeriod('2026-01-15')->id, (string) $this->user->id);

    $this->postJson('/api/v1/accounting/ledger-entries', legacyLedgerPayload('GL-CLOSED', '2026-01-15', '10'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fiscal_period');

    $this->postJson('/api/v1/accounting/ledger-entries', legacyLedgerPayload('GL-NO-YEAR', '2030-01-15', '10'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fiscal_period');

    expect(JournalEntry::withoutGlobalScopes()->whereIn('entry_number', ['GL-CLOSED', 'GL-NO-YEAR'])->count())->toBe(0)
        ->and(DB::table('general_ledger_entries')->count())->toBe(0);
});

test('the legacy post balances exactly and refuses a reused entry number', function (): void {
    $payload = legacyLedgerPayload('GL-UNBALANCED', '2026-03-10', '100');
    $payload['lines'][1]['credit'] = '99.995';

    $this->postJson('/api/v1/accounting/ledger-entries', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('title', 'Unbalanced Ledger Entry');

    $this->postJson('/api/v1/accounting/ledger-entries', legacyLedgerPayload('GL-ONCE', '2026-03-10', '10'))->assertCreated();
    $this->postJson('/api/v1/accounting/ledger-entries', legacyLedgerPayload('GL-ONCE', '2026-03-11', '10'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('entry_number');

    expect(JournalEntry::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

test('the legacy listing and the libro giornale show the figures of the module', function (): void {
    $this->postJson('/api/v1/accounting/ledger-entries', legacyLedgerPayload('GL-A', '2026-03-10', '100'))->assertCreated();
    $this->postJson('/api/v1/accounting/ledger-entries', legacyLedgerPayload('GL-B', '2026-03-20', '50.25'))->assertCreated();
    $this->postJson('/api/v1/general-ledger/journals', [
        'fiscal_period_id' => legacyLedgerPeriod('2026-03-25')->id,
        'posting_date' => '2026-03-25',
        'description' => 'Manual module entry',
        'lines' => [
            ['account_id' => $this->cash->id, 'debit_amount' => 20],
            ['account_id' => $this->revenue->id, 'credit_amount' => 20],
        ],
    ])->assertCreated();

    $legacy = $this->getJson('/api/v1/accounting/ledger-entries')->assertOk();
    $module = $this->getJson('/api/v1/general-ledger/journals')->assertOk();

    expect($legacy->json('data.total'))->toBe(3)
        ->and(collect($legacy->json('data.data'))->pluck('id')->sort()->values()->all())->toBe(collect($module->json('data'))->pluck('id')->sort()->values()->all())
        ->and(collect($legacy->json('data.data'))->sum('total_amount'))->toEqual(170.25)
        ->and($legacy->json('data.data.0'))->toHaveKeys(['id', 'entry_number', 'date', 'description', 'reference_type', 'reference_id', 'is_locked', 'journal_number', 'fiscal_period_id'])
        ->and($legacy->json('data.data.0.entry_number'))->not->toBeNull()
        ->and($legacy->json('data.data.0.is_locked'))->toBeTrue();

    $trialBalance = $this->getJson('/api/v1/general-ledger/financial-statements/trial-balance?fiscal_period_id='.legacyLedgerPeriod('2026-03-10')->id)->assertOk();
    expect($trialBalance->json('data.total_debit'))->toEqual(collect($legacy->json('data.data'))->sum('total_amount'));

    $export = $this->get("/api/v1/general-ledger/journal-book/export?fiscal_year_id={$this->year->id}")->assertOk();
    $lines = explode("\r\n", rtrim(substr($export->getContent(), 3), "\r\n"));
    $rows = array_map(fn (string $line): array => str_getcsv($line, ';', '"', ''), array_slice($lines, 1));

    expect($rows)->toHaveCount(6)
        ->and(array_values(array_unique(array_column($rows, 0))))->toBe(['1', '2', '3']);
});

test('the legacy ledger rows are migrated once, closed periods are logged and skipped, nothing is deleted', function (): void {
    $open = legacyLedgerRow('OLD-1', '2026-04-10', '80.00', '80.00');
    $secondRow = legacyLedgerRow('OLD-2', '2026-04-11', '20.00', '20.00');
    $closed = legacyLedgerRow('OLD-CLOSED', '2026-01-20', '30.00', '30.00');
    $noPeriod = legacyLedgerRow('OLD-2019', '2019-05-05', '5.00', '5.00');
    $unbalanced = legacyLedgerRow('OLD-UNBAL', '2026-04-12', '10.00', '9.99');
    $deleted = legacyLedgerRow('OLD-DEL', '2026-04-13', '1.00', '1.00');
    $deleted->delete();

    app(PeriodClosingServiceInterface::class)->closePeriod((string) legacyLedgerPeriod('2026-01-20')->id, (string) $this->user->id);
    app(TenantContext::class)->clear();

    $first = app(LegacyLedgerMigrator::class)->migrate();

    expect($first)->toBe([
        'migrated' => 2,
        'already_migrated' => 0,
        'skipped' => [LegacyLedgerMigrator::REASON_NO_FISCAL_PERIOD => 1, LegacyLedgerMigrator::REASON_CLOSED_PERIOD => 1, LegacyLedgerMigrator::REASON_UNBALANCED => 1, LegacyLedgerMigrator::REASON_SOFT_DELETED => 1],
    ]);

    $copied = JournalEntry::withoutGlobalScopes()->where('source_document_type', LegacyLedgerMigrator::SOURCE)->where('source_document_id', $open->id)->sole();
    expect($copied->entry_number)->toBe('OLD-1')
        ->and($copied->posting_date->toDateString())->toBe('2026-04-10')
        ->and((string) $copied->fiscal_period_id)->toBe((string) legacyLedgerPeriod('2026-04-10')->id)
        ->and($copied->status)->toBe('posted')
        ->and($copied->journal_number)->not->toBeNull()
        ->and((float) $copied->total_amount)->toEqual(80.0);

    $secondRun = app(LegacyLedgerMigrator::class)->migrate();

    expect($secondRun['migrated'])->toBe(0)
        ->and($secondRun['already_migrated'])->toBe(2)
        ->and(JournalEntry::withoutGlobalScopes()->where('source_document_type', LegacyLedgerMigrator::SOURCE)->count())->toBe(2)
        ->and(GeneralLedgerEntry::withTrashed()->count())->toBe(6)
        ->and(DB::table('general_ledger_lines')->count())->toBe(12)
        ->and(legacyLedgerPeriod('2026-01-20')->fresh()->status)->toBe('closed');

    $log = DB::table(LegacyLedgerMigrator::LOG_TABLE)->get()->keyBy('general_ledger_entry_id');
    expect($log)->toHaveCount(6)
        ->and($log[$closed->id]->status)->toBe(LegacyLedgerMigrator::STATUS_SKIPPED)
        ->and($log[$closed->id]->reason)->toBe(LegacyLedgerMigrator::REASON_CLOSED_PERIOD)
        ->and((int) $log[$closed->id]->attempts)->toBe(2)
        ->and($log[$noPeriod->id]->reason)->toBe(LegacyLedgerMigrator::REASON_NO_FISCAL_PERIOD)
        ->and($log[$unbalanced->id]->reason)->toBe(LegacyLedgerMigrator::REASON_UNBALANCED)
        ->and($log[$secondRow->id]->status)->toBe(LegacyLedgerMigrator::STATUS_MIGRATED)
        ->and($log[$open->id]->journal_entry_id)->toBe((string) $copied->id);

    $this->artisan('ledger:migrate-legacy-entries')->expectsOutputToContain('already migrated: 2')->assertSuccessful();
});

test('a customer payment and a bank reconciliation reach the journal exactly once', function (): void {
    $customer = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'customer', 'company_name' => 'Cliente Srl', 'country' => 'IT']);
    $date = now()->toDateString();
    if (now()->year !== 2026) {
        app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, now()->year);
    }

    $invoice = fn (): string => $this->postJson('/api/v1/sales/invoices', [
        'contact_id' => $customer->id, 'invoice_type' => 'standard', 'date' => $date, 'currency' => 'EUR', 'jurisdiction' => 'IT',
        'items' => [['description' => 'Consulenza', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22]],
    ])->assertCreated()->json('data.id');

    $paidByHand = $invoice();
    $this->postJson("/api/v1/sales/invoices/{$paidByHand}/payments", ['amount' => 122.0, 'paid_at' => $date])->assertSuccessful();
    $payment = Payment::query()->where('invoice_id', $paidByHand)->sole();

    $paidByBank = $invoice();
    $statement = BankStatement::create(['tenant_id' => $this->tenant->id, 'source_format' => 'camt053', 'account_iban' => 'IT60X0542811101000000123456', 'statement_date' => $date, 'currency' => 'EUR', 'total_entries' => 1, 'status' => 'draft']);
    $line = BankStatementLine::create(['tenant_id' => $this->tenant->id, 'bank_statement_id' => $statement->id, 'line_number' => 1, 'booking_date' => $date, 'value_date' => $date, 'amount' => 122.0, 'direction' => 'credit', 'description' => 'Bonifico', 'reconciled' => false]);
    $this->postJson("/api/v1/banking/statements/lines/{$line->id}/manual-match", ['invoice_id' => $paidByBank])->assertOk();
    $bankPayment = Payment::query()->where('bank_statement_line_id', $line->id)->sole();

    $entries = fn (string $type, string $id): int => JournalEntry::withoutGlobalScopes()->where('source_document_type', $type)->where('source_document_id', $id)->count();

    expect($entries('sales_invoice', $paidByHand))->toBe(1)
        ->and($entries('customer_payment', (string) $payment->id))->toBe(1)
        ->and($entries('sales_invoice', $paidByBank))->toBe(1)
        ->and($entries('customer_payment', (string) $bankPayment->id))->toBe(1)
        ->and(DB::table('general_ledger_entries')->count())->toBe(0);

    $listed = collect($this->getJson('/api/v1/accounting/ledger-entries')->assertOk()->json('data.data'));
    expect($listed->where('reference_type', 'customer_payment')->pluck('reference_id')->sort()->values()->all())
        ->toBe(collect([(string) $payment->id, (string) $bankPayment->id])->sort()->values()->all());
});
