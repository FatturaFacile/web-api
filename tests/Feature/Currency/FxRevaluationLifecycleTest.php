<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\ExchangeRate;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Treasury\Currency\Application\Handlers\ReverseFxRevaluationHandler;
use Modules\Treasury\Currency\Infrastructure\Jobs\ReverseRevaluationsJob;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencyFxRevaluation;
use Modules\Treasury\Currency\Infrastructure\Persistence\RealizedFxDifference;

uses(RefreshDatabase::class);

/**
 * The period-end revaluation of a EUR company holding a USD receivable, from the simulation to the collection: simulate, inspect,
 * post with an Idempotency-Key (200 or 409), reverse on the first day of the next period (by request and by the job), and the
 * collection after it, measured against the original document rate, so the exchange difference is counted once.
 * The invoice is 1000 USD + 22% VAT = 1220 USD, frozen at 1 EUR = 1.25 USD (976.00 EUR).
 */
function fxrAccount(Tenant $tenant, string $code, string $type, string $normal): ChartOfAccount
{
    return ChartOfAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'company_id' => null,
        'account_code' => $code,
        'account_type' => $type,
        'account_class' => $type,
        'normal_balance' => $normal,
        'is_active' => true,
        'allows_manual_posting' => true,
    ]);
}

function fxrInvoice(object $test): string
{
    return $test->postJson('/api/v1/sales/invoices', [
        'contact_id' => $test->customer->id, 'invoice_type' => 'standard', 'date' => '2026-06-15', 'currency' => 'USD', 'jurisdiction' => 'IT',
        'items' => [['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 22]],
    ])->assertCreated()->json('data.id');
}

/**
 * @return array<string, mixed>
 */
function fxrSimulate(object $test, string $periodEnd = '2026-06-30', string $type = 'monthly'): array
{
    return $test->postJson('/api/v1/accounting/fx-revaluations', ['period_end' => $periodEnd, 'period_type' => $type])->assertCreated()->json('data');
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    $this->tenant = Tenant::create(['name' => 'Alfa FX', 'legal_name' => 'Alfa FX SpA', 'country' => 'IT', 'default_currency' => 'EUR', 'vat_number' => '01234567890']);
    $this->otherTenant = Tenant::create(['name' => 'Beta FX', 'legal_name' => 'Beta FX SpA', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->user = User::create(['name' => 'Owner', 'email' => Str::random(12).'@example.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    $this->user->tenants()->attach($this->otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);

    app(TenantContext::class)->setTenant($this->tenant);
    Sanctum::actingAs($this->user);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
    app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2026);

    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'customer', 'company_name' => 'Acme Inc', 'country' => 'IT']);
    $this->fxGain = fxrAccount($this->tenant, '4810', 'revenue', 'credit');
    $this->fxLoss = fxrAccount($this->tenant, '6810', 'expense', 'debit');
    $this->rounding = fxrAccount($this->tenant, '6960', 'expense', 'debit');

    $this->putJson('/api/v1/general-ledger/settings', ['posting_accounts' => [
        'fx_realised_gain' => $this->fxGain->id, 'fx_realised_loss' => $this->fxLoss->id,
        'fx_unrealised_gain' => $this->fxGain->id, 'fx_unrealised_loss' => $this->fxLoss->id, 'rounding' => $this->rounding->id,
    ]])->assertOk();

    // 1 EUR = 1.25 USD at the invoice (0.8 EUR per USD), 1.20 at the month end (0.833333), 1.10 on 1 July (0.909091).
    ExchangeRate::create(['source' => 'EUR', 'target' => 'USD', 'rate' => '1.250000', 'effective_date' => '2026-06-15']);
    ExchangeRate::create(['source' => 'EUR', 'target' => 'USD', 'rate' => '1.200000', 'effective_date' => '2026-06-25']);
    ExchangeRate::create(['source' => 'EUR', 'target' => 'USD', 'rate' => '1.100000', 'effective_date' => '2026-07-01']);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('the simulation values the open receivable at the closing rate and keeps a draft with its lines', function (): void {
    fxrInvoice($this);
    $this->travelTo(CarbonImmutable::parse('2026-06-30 10:00:00'));

    $draft = fxrSimulate($this);
    $line = $draft['lines'][0];

    expect($draft['status'])->toBe('draft')
        ->and($draft['period_end'])->toBe('2026-06-30')
        ->and($draft['period_type'])->toBe('monthly')
        ->and($draft['functional_currency'])->toBe('EUR')
        ->and($draft['total_gain'])->toBe('40.67')
        ->and($draft['total_loss'])->toBe('0.00')
        ->and($draft['net_result'])->toBe('40.67')
        // Italian companies keep the net unrealised gain recognised in the income statement as a non-distributable reserve.
        ->and($draft['reserve_amount'])->toBe('40.67')
        ->and($draft['reverse_on'])->toBe('2026-07-01')
        ->and($draft['journal_entry_id'])->toBeNull()
        ->and($draft['lines'])->toHaveCount(1)
        ->and($line['line_type'])->toBe('receivable')
        ->and($line['currency'])->toBe('USD')
        ->and($line['currency_balance'])->toBe('1220.0000')
        ->and($line['book_value'])->toBe('976.00')
        ->and($line['closing_rate'])->toBe('0.833333')
        ->and($line['revalued_value'])->toBe('1016.67')
        ->and($line['difference'])->toBe('40.67')
        ->and($line['result'])->toBe('gain')
        ->and($line['treatment'])->toBe('recognise');

    // Nothing is posted by a simulation.
    expect(JournalEntry::withoutGlobalScopes()->where('source_document_type', 'currency_fx_revaluation')->count())->toBe(0);

    $preview = $this->getJson('/api/v1/accounting/fx-revaluations/'.$draft['id'])->assertOk()->json('data');
    $list = $this->getJson('/api/v1/accounting/fx-revaluations')->assertOk();

    expect($preview['lines'])->toHaveCount(1)
        ->and($preview['net_result'])->toBe('40.67')
        ->and($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0'))->not->toHaveKey('lines')
        ->and($list->json('meta.total'))->toBe(1);
});

test('the currency exposure reports the historical and the current value of the open receivable', function (): void {
    fxrInvoice($this);

    $exposure = $this->getJson('/api/v1/currencies/exposure?as_of=2026-06-30')->assertOk()->json('data');
    $usd = $exposure['items'][0];

    expect($exposure['as_of'])->toBe('2026-06-30')
        ->and($exposure['functional_currency'])->toBe('EUR')
        ->and($exposure['items'])->toHaveCount(1)
        ->and($usd['currency'])->toBe('USD')
        ->and($usd['receivables'])->toBe('1220.00')
        ->and($usd['payables'])->toBe('0.00')
        ->and($usd['bank'])->toBe('0.00')
        ->and($usd['cash'])->toBe('0.00')
        ->and($usd['net_amount'])->toBe('1220.00')
        ->and($usd['historical_value'])->toBe('976.00')
        ->and($usd['closing_rate'])->toBe('0.833333')
        ->and($usd['current_value'])->toBe('1016.67')
        ->and($usd['unrealized_gain_loss'])->toBe('40.67')
        ->and($usd['rate_missing'])->toBeFalse()
        ->and($exposure['totals'])->toBe(['historical_value' => '976.00', 'current_value' => '1016.67', 'unrealized_gain_loss' => '40.67']);

    // Without a rate within five days nothing is invented: the currency is flagged and left out of the totals.
    $later = $this->getJson('/api/v1/currencies/exposure?as_of=2026-12-31')->assertOk()->json('data');

    expect($later['items'][0]['rate_missing'])->toBeTrue()
        ->and($later['items'][0]['current_value'])->toBeNull()
        ->and($later['items'][0]['unrealized_gain_loss'])->toBeNull()
        ->and($later['items'][0]['historical_value'])->toBe('976.00')
        ->and($later['totals']['current_value'])->toBe('0.00');
});

test('posting is idempotent: the same key answers 200 again, another key is a 409', function (): void {
    fxrInvoice($this);
    $this->travelTo(CarbonImmutable::parse('2026-06-30 10:00:00'));
    $draft = fxrSimulate($this);
    $url = '/api/v1/accounting/fx-revaluations/'.$draft['id'].'/post';

    $this->postJson($url)->assertStatus(422)->assertJsonPath('code', 'idempotency_key_required');
    $this->postJson($url, [], ['Idempotency-Key' => 'short'])->assertStatus(422)->assertJsonPath('code', 'idempotency_key_required');

    $posted = $this->postJson($url, [], ['Idempotency-Key' => 'fx-post-0001'])->assertOk()->json('data');
    $replay = $this->postJson($url, [], ['Idempotency-Key' => 'fx-post-0001'])->assertOk()->json('data');
    $this->postJson($url, [], ['Idempotency-Key' => 'fx-post-0002'])->assertStatus(409)->assertJsonPath('code', 'revaluation_already_posted');

    $entry = JournalEntry::withoutGlobalScopes()->with('lines')->findOrFail($posted['journal_entry_id']);
    $gain = $entry->lines->firstWhere('account_id', $this->fxGain->id);
    $receivable = $entry->lines->firstWhere('account_id', '!=', $this->fxGain->id);

    expect($posted['status'])->toBe('posted')
        ->and($posted['posted_at'])->not->toBeNull()
        ->and($replay['journal_entry_id'])->toBe($posted['journal_entry_id'])
        ->and(JournalEntry::withoutGlobalScopes()->where('source_document_type', 'currency_fx_revaluation')->count())->toBe(1)
        ->and($entry->isBalanced())->toBeTrue()
        ->and($entry->lines)->toHaveCount(2)
        ->and((string) $receivable->debit_amount)->toBe('40.6700')
        ->and((string) $gain->credit_amount)->toBe('40.6700')
        ->and($entry->posting_date->toDateString())->toBe('2026-06-30');
});

test('a period already revalued and posted cannot be posted twice from two drafts', function (): void {
    fxrInvoice($this);
    $this->travelTo(CarbonImmutable::parse('2026-06-30 10:00:00'));
    $first = fxrSimulate($this);
    $second = fxrSimulate($this);

    $this->postJson('/api/v1/accounting/fx-revaluations/'.$first['id'].'/post', [], ['Idempotency-Key' => 'fx-post-aaaa'])->assertOk();
    $this->postJson('/api/v1/accounting/fx-revaluations/'.$second['id'].'/post', [], ['Idempotency-Key' => 'fx-post-bbbb'])->assertStatus(409)->assertJsonPath('code', 'revaluation_period_conflict');

    expect(JournalEntry::withoutGlobalScopes()->where('source_document_type', 'currency_fx_revaluation')->count())->toBe(1);
});

test('reversal on the first day of the next period brings the book value back, and is idempotent', function (): void {
    fxrInvoice($this);
    $this->travelTo(CarbonImmutable::parse('2026-06-30 10:00:00'));
    $draft = fxrSimulate($this);
    $reverse = '/api/v1/accounting/fx-revaluations/'.$draft['id'].'/reverse';

    $this->postJson($reverse)->assertStatus(409)->assertJsonPath('code', 'revaluation_not_posted');

    $posted = $this->postJson('/api/v1/accounting/fx-revaluations/'.$draft['id'].'/post', [], ['Idempotency-Key' => 'fx-post-0001'])->assertOk()->json('data');
    $this->travelTo(CarbonImmutable::parse('2026-07-02 10:00:00'));

    $reversed = $this->postJson($reverse)->assertOk()->json('data');
    $again = $this->postJson($reverse)->assertOk()->json('data');

    $reversal = JournalEntry::withoutGlobalScopes()->with('lines')->findOrFail($reversed['reversal_journal_entry_id']);
    $original = JournalEntry::withoutGlobalScopes()->with('lines')->findOrFail($posted['journal_entry_id']);

    expect($reversed['status'])->toBe('reversed')
        ->and($reversed['reversed_at'])->not->toBeNull()
        ->and($reversed['reverse_on'])->toBe('2026-07-01')
        ->and($again['reversal_journal_entry_id'])->toBe($reversed['reversal_journal_entry_id'])
        ->and($reversal->posting_date->toDateString())->toBe('2026-07-01')
        ->and($reversal->isBalanced())->toBeTrue()
        // The reversal mirrors the revaluation: what was debited is credited.
        ->and(round((float) $reversal->lines->sum('credit_amount'), 2))->toBe(round((float) $original->lines->sum('debit_amount'), 2))
        ->and(JournalEntry::withoutGlobalScopes()->whereKey($reversed['reversal_journal_entry_id'])->count())->toBe(1);

    $this->postJson('/api/v1/accounting/fx-revaluations/'.$draft['id'].'/post', [], ['Idempotency-Key' => 'fx-post-0003'])->assertStatus(409)->assertJsonPath('code', 'revaluation_already_reversed');
});

test('the reversal job reverses what is due, once, and leaves what is not due', function (): void {
    fxrInvoice($this);
    $this->travelTo(CarbonImmutable::parse('2026-06-30 10:00:00'));
    $draft = fxrSimulate($this);
    $this->postJson('/api/v1/accounting/fx-revaluations/'.$draft['id'].'/post', [], ['Idempotency-Key' => 'fx-post-0001'])->assertOk();

    $job = fn (string $today): int => (new ReverseRevaluationsJob($today))->handle(app(ReverseFxRevaluationHandler::class), app(TenantContext::class));

    $status = fn (): string => CurrencyFxRevaluation::query()->withoutGlobalScopes()->find($draft['id'])->status->value;

    // Not due yet on the last day of the period.
    expect($job('2026-06-30'))->toBe(0)->and($status())->toBe('posted');

    // Due on the first day of the next period, and reversed once.
    $this->travelTo(CarbonImmutable::parse('2026-07-01 02:00:00'));

    expect($job('2026-07-01'))->toBe(1)
        ->and($status())->toBe('reversed')
        ->and($job('2026-07-02'))->toBe(0)
        ->and(JournalEntry::withoutGlobalScopes()->where('source_document_id', $draft['id'])->count())->toBeGreaterThanOrEqual(1);
});

test('the collection after a revaluation is measured against the original document rate, so nothing is counted twice', function (): void {
    $invoiceId = fxrInvoice($this);
    $this->travelTo(CarbonImmutable::parse('2026-06-30 10:00:00'));
    $draft = fxrSimulate($this);
    $this->postJson('/api/v1/accounting/fx-revaluations/'.$draft['id'].'/post', [], ['Idempotency-Key' => 'fx-post-0001'])->assertOk();

    $this->travelTo(CarbonImmutable::parse('2026-07-02 10:00:00'));
    $this->postJson('/api/v1/accounting/fx-revaluations/'.$draft['id'].'/reverse')->assertOk();
    $this->postJson("/api/v1/sales/invoices/{$invoiceId}/payments", ['amount' => 1220, 'paid_at' => '2026-07-02'])->assertSuccessful();

    $payment = Payment::query()->where('invoice_id', $invoiceId)->sole();
    $difference = RealizedFxDifference::query()->withoutGlobalScopes()->where('allocation_id', 'customer_payment:'.$payment->id)->sole();

    // 1220 USD collected at 0.909091 = 1109.09 EUR against 976.00 booked at the invoice rate: +133.09 (not +92.42 from the revalued 1016.67).
    expect($difference->direction->value)->toBe('receivable')
        ->and($difference->currency)->toBe('USD')
        ->and((string) $difference->settled_amount)->toBe('1220.0000')
        ->and((string) $difference->document_rate)->toBe('0.800000')
        ->and((string) $difference->payment_rate)->toBe('0.909091')
        ->and((string) $difference->document_value)->toBe('976.00')
        ->and((string) $difference->payment_value)->toBe('1109.09')
        ->and((string) $difference->difference)->toBe('133.09')
        ->and((string) $difference->effect)->toBe('133.09')
        ->and($difference->result->value)->toBe('gain')
        ->and($difference->payment_date->toDateString())->toBe('2026-07-02')
        // The ledger payment entry already carries the difference: the engine records it and posts nothing again.
        ->and($difference->ledger_posted)->toBeTrue()
        ->and($difference->journal_entry_id)->toBeNull()
        ->and(JournalEntry::withoutGlobalScopes()->where('source_document_type', 'currency_realized_fx')->count())->toBe(0);
});

test('a simulation needs a closing rate within five days and a valid period end', function (): void {
    fxrInvoice($this);
    $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00:00'));

    $this->postJson('/api/v1/accounting/fx-revaluations', ['period_end' => '2026-09-30', 'period_type' => 'monthly'])->assertStatus(422)->assertJsonPath('code', 'exchange_rate_missing');
    $this->postJson('/api/v1/accounting/fx-revaluations', ['period_end' => '2026-06-29', 'period_type' => 'monthly'])->assertStatus(422)->assertJsonPath('code', 'period_end_invalid');
    $this->postJson('/api/v1/accounting/fx-revaluations', ['period_end' => '2026-05-31', 'period_type' => 'quarterly'])->assertStatus(422)->assertJsonPath('code', 'period_end_invalid');
    $this->postJson('/api/v1/accounting/fx-revaluations', ['period_end' => '2026-06-30', 'period_type' => 'weekly'])->assertStatus(422)->assertJsonValidationErrors(['period_type']);
    $this->postJson('/api/v1/accounting/fx-revaluations', ['period_type' => 'monthly'])->assertStatus(422)->assertJsonValidationErrors(['period_end']);

    expect(CurrencyFxRevaluation::query()->withoutGlobalScopes()->count())->toBe(0);

    // A quarter end and a year end are valid period ends too.
    $this->postJson('/api/v1/accounting/fx-revaluations', ['period_end' => '2026-06-30', 'period_type' => 'quarterly'])->assertCreated();
});

test('the simulation can be limited to some currencies, and a company without foreign items has nothing to revalue', function (): void {
    fxrInvoice($this);
    $this->travelTo(CarbonImmutable::parse('2026-06-30 10:00:00'));

    $this->postJson('/api/v1/accounting/fx-revaluations', ['period_end' => '2026-06-30', 'period_type' => 'monthly', 'currencies' => ['GBP']])
        ->assertStatus(422)->assertJsonPath('code', 'nothing_to_revalue');
    $this->postJson('/api/v1/accounting/fx-revaluations', ['period_end' => '2026-06-30', 'period_type' => 'monthly', 'currencies' => ['usd']])->assertCreated();

    Sanctum::actingAs($this->user);
    $this->withHeader('X-Tenant-Id', $this->otherTenant->id);
    app(TenantContext::class)->setTenant($this->otherTenant);

    $this->postJson('/api/v1/accounting/fx-revaluations', ['period_end' => '2026-06-30', 'period_type' => 'monthly'])->assertStatus(422)->assertJsonPath('code', 'nothing_to_revalue');
});

test('a revaluation of one company is invisible to another', function (): void {
    fxrInvoice($this);
    $this->travelTo(CarbonImmutable::parse('2026-06-30 10:00:00'));
    $draft = fxrSimulate($this);

    app(TenantContext::class)->setTenant($this->otherTenant);
    $this->withHeader('X-Tenant-Id', $this->otherTenant->id);

    $this->getJson('/api/v1/accounting/fx-revaluations/'.$draft['id'])->assertNotFound()->assertJsonPath('code', 'revaluation_not_found');
    $this->postJson('/api/v1/accounting/fx-revaluations/'.$draft['id'].'/post', [], ['Idempotency-Key' => 'fx-post-0001'])->assertNotFound();
    $this->postJson('/api/v1/accounting/fx-revaluations/'.$draft['id'].'/reverse')->assertNotFound();
    expect($this->getJson('/api/v1/accounting/fx-revaluations')->json('data'))->toBe([]);
});

test('only finance simulates, posts and reverses', function (): void {
    $member = User::create(['name' => 'Member', 'email' => Str::random(12).'@example.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $member->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    Sanctum::actingAs($member);

    $this->postJson('/api/v1/accounting/fx-revaluations', ['period_end' => '2026-06-30', 'period_type' => 'monthly'])->assertForbidden();
    $this->getJson('/api/v1/accounting/fx-revaluations')->assertForbidden();
});

test('when the company requires four-eyes approval, whoever simulated the revaluation cannot post it', function (): void {
    fxrInvoice($this);
    $this->travelTo(CarbonImmutable::parse('2026-06-30 10:00:00'));
    DB::table('gl_ledger_settings')->where('tenant_id', $this->tenant->id)->update(['manual_journal_approval_required' => true]);
    $draft = fxrSimulate($this);

    $this->postJson('/api/v1/accounting/fx-revaluations/'.$draft['id'].'/post', [], ['Idempotency-Key' => 'fx-post-0001'])->assertForbidden();

    expect(CurrencyFxRevaluation::query()->withoutGlobalScopes()->find($draft['id'])->status->value)->toBe('draft');

    $accountant = User::create(['name' => 'Accountant', 'email' => Str::random(12).'@example.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $accountant->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'accountant']);
    Sanctum::actingAs($accountant);

    // Another authorised person is not stopped by the policy.
    expect($this->postJson('/api/v1/accounting/fx-revaluations/'.$draft['id'].'/post', [], ['Idempotency-Key' => 'fx-post-0002'])->status())->not->toBe(403);
});
