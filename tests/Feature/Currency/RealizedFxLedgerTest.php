<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Treasury\Currency\Domain\Enums\FxDirection;
use Modules\Treasury\Currency\Domain\Events\PaymentAllocated;
use Modules\Treasury\Currency\Domain\Exceptions\CurrencyDomainException;
use Modules\Treasury\Currency\Domain\Exceptions\ExchangeRateMissingException;
use Modules\Treasury\Currency\Infrastructure\Persistence\RealizedFxDifference;

require_once __DIR__.'/CurrencySupport.php';

uses(RefreshDatabase::class);

/**
 * A receipt or payment allocated to an open item whose settlement entry did not carry the exchange difference: the Currency module
 * records it once per allocation and posts it (PaymentAllocated → PostRealizedFxDifferenceCommand): gain or loss against the
 * rate frozen on the document, partial settlements, a payment in another currency, a document in the functional currency, and the
 * refusals (no rate, no account).
 */
function fxrlAccount(Tenant $tenant, string $code, string $type, string $normal): ChartOfAccount
{
    return ChartOfAccount::factory()->create([
        'tenant_id' => $tenant->id, 'company_id' => null, 'account_code' => $code, 'account_type' => $type, 'account_class' => $type,
        'normal_balance' => $normal, 'is_active' => true, 'allows_manual_posting' => true,
    ]);
}

function fxrlAllocation(object $test, string $id, array $overrides = []): PaymentAllocated
{
    return new PaymentAllocated(...array_merge([
        'tenantId' => (string) $test->tenant->id,
        'allocationId' => $id,
        'direction' => FxDirection::Receivable,
        'documentCurrency' => 'USD',
        'settledAmount' => '1000.00',
        'documentRate' => '0.800000',
        'paymentDate' => '2026-07-02',
        'paymentRate' => '0.833333',
        'documentReference' => 'INV-1',
        'ledgerPostedDifference' => false,
        'actorId' => (string) $test->user->id,
    ], $overrides));
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-02 10:00:00'));
    $this->tenant = Tenant::create(['name' => 'Alfa FX', 'legal_name' => 'Alfa FX SpA', 'country' => 'IT', 'default_currency' => 'EUR', 'vat_number' => '01234567890']);
    $this->otherTenant = Tenant::create(['name' => 'Beta FX', 'legal_name' => 'Beta FX SpA', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->user = User::create(['name' => 'Owner', 'email' => Str::random(12).'@example.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);

    app(TenantContext::class)->setTenant($this->tenant);
    Sanctum::actingAs($this->user);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
    app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2026);

    $this->receivables = fxrlAccount($this->tenant, '1200', 'asset', 'debit');
    $this->payables = fxrlAccount($this->tenant, '2200', 'liability', 'credit');
    $this->gain = fxrlAccount($this->tenant, '4810', 'revenue', 'credit');
    $this->loss = fxrlAccount($this->tenant, '6810', 'expense', 'debit');

    $this->putJson('/api/v1/general-ledger/settings', ['posting_accounts' => [
        'receivables' => $this->receivables->id, 'payables' => $this->payables->id,
        'fx_realised_gain' => $this->gain->id, 'fx_realised_loss' => $this->loss->id,
        'fx_unrealised_gain' => $this->gain->id, 'fx_unrealised_loss' => $this->loss->id,
    ]])->assertOk();
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * @return array<string, array{debit: string, credit: string}>
 */
function fxrlLines(string $journalEntryId): array
{
    $entry = JournalEntry::withoutGlobalScopes()->with('lines')->findOrFail($journalEntryId);
    $lines = [];

    foreach ($entry->lines as $line) {
        $lines[(string) $line->account_id] = ['debit' => (string) $line->debit_amount, 'credit' => (string) $line->credit_amount];
    }

    expect($entry->isBalanced())->toBeTrue();

    return $lines;
}

test('a receivable collected at a higher rate books a realised gain against the receivable account, once', function (): void {
    $event = fxrlAllocation($this, 'alloc-gain');

    Event::dispatch($event);
    Event::dispatch($event);

    $record = RealizedFxDifference::query()->withoutGlobalScopes()->where('allocation_id', 'alloc-gain')->sole();
    $lines = fxrlLines((string) $record->journal_entry_id);

    expect($record->result->value)->toBe('gain')
        ->and((string) $record->difference)->toBe('33.33')
        ->and((string) $record->effect)->toBe('33.33')
        ->and((string) $record->document_value)->toBe('800.00')
        ->and((string) $record->payment_value)->toBe('833.33')
        ->and($record->ledger_posted)->toBeFalse()
        ->and(RealizedFxDifference::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(JournalEntry::withoutGlobalScopes()->where('source_document_type', 'currency_realized_fx')->count())->toBe(1)
        ->and($lines[(string) $this->receivables->id])->toBe(['debit' => '33.3300', 'credit' => '0.0000'])
        ->and($lines[(string) $this->gain->id])->toBe(['debit' => '0.0000', 'credit' => '33.3300']);
});

test('a receivable collected at a lower rate books a realised loss', function (): void {
    Event::dispatch(fxrlAllocation($this, 'alloc-loss', ['paymentRate' => '0.780000']));

    $record = RealizedFxDifference::query()->withoutGlobalScopes()->where('allocation_id', 'alloc-loss')->sole();
    $lines = fxrlLines((string) $record->journal_entry_id);

    expect($record->result->value)->toBe('loss')
        ->and((string) $record->effect)->toBe('-20.00')
        ->and($lines[(string) $this->loss->id])->toBe(['debit' => '20.0000', 'credit' => '0.0000'])
        ->and($lines[(string) $this->receivables->id])->toBe(['debit' => '0.0000', 'credit' => '20.0000']);
});

test('a payable paid at a higher rate is a loss against the payable account', function (): void {
    Event::dispatch(fxrlAllocation($this, 'alloc-payable', ['direction' => FxDirection::Payable]));

    $record = RealizedFxDifference::query()->withoutGlobalScopes()->where('allocation_id', 'alloc-payable')->sole();
    $lines = fxrlLines((string) $record->journal_entry_id);

    expect($record->direction->value)->toBe('payable')
        // The raw difference is the payment minus the document; for a payable a positive one is a loss.
        ->and((string) $record->difference)->toBe('33.33')
        ->and((string) $record->effect)->toBe('-33.33')
        ->and($record->result->value)->toBe('loss')
        ->and($lines[(string) $this->loss->id])->toBe(['debit' => '33.3300', 'credit' => '0.0000'])
        ->and($lines[(string) $this->payables->id])->toBe(['debit' => '0.0000', 'credit' => '33.3300']);
});

test('a partial settlement carries the difference of the settled portion', function (): void {
    Event::dispatch(fxrlAllocation($this, 'alloc-part-1', ['settledAmount' => '400.00', 'itemAmount' => '1000.00']));
    Event::dispatch(fxrlAllocation($this, 'alloc-part-2', ['settledAmount' => '600.00', 'itemAmount' => '1000.00']));

    $first = RealizedFxDifference::query()->withoutGlobalScopes()->where('allocation_id', 'alloc-part-1')->sole();
    $second = RealizedFxDifference::query()->withoutGlobalScopes()->where('allocation_id', 'alloc-part-2')->sole();

    expect((string) $first->difference)->toBe('13.33')
        ->and((string) $second->difference)->toBe('20.00')
        ->and((string) $first->settled_amount)->toBe('400.0000');
});

test('an invoice in one currency paid in another is measured on the functional counter-value of the payment', function (): void {
    // A 1000 USD invoice frozen at 0.80 (800.00 EUR) settled with 810.00 EUR.
    Event::dispatch(fxrlAllocation($this, 'alloc-cross', ['paymentRate' => null, 'paymentFunctionalAmount' => '810.00']));

    $record = RealizedFxDifference::query()->withoutGlobalScopes()->where('allocation_id', 'alloc-cross')->sole();

    expect((string) $record->difference)->toBe('10.00')
        ->and((string) $record->payment_value)->toBe('810.00')
        ->and($record->payment_rate)->toBeNull()
        ->and($record->result->value)->toBe('gain');
});

test('without a stated payment rate the rate of the payment day is used, and without one the allocation is refused', function (): void {
    fxStoreRate('EUR', 'USD', '1.100000', '2026-07-01');

    Event::dispatch(fxrlAllocation($this, 'alloc-lookup', ['paymentRate' => null]));

    $record = RealizedFxDifference::query()->withoutGlobalScopes()->where('allocation_id', 'alloc-lookup')->sole();

    // 1000 USD at 1 / 1.10 = 0.909091: 909.09 EUR against 800.00.
    expect((string) $record->payment_rate)->toBe('0.909091')
        ->and((string) $record->difference)->toBe('109.09');

    expect(fn () => Event::dispatch(fxrlAllocation($this, 'alloc-nolookup', ['paymentRate' => null, 'paymentDate' => '2026-09-01'])))->toThrow(ExchangeRateMissingException::class)
        ->and(RealizedFxDifference::query()->withoutGlobalScopes()->where('allocation_id', 'alloc-nolookup')->count())->toBe(0);
});

test('a document in the functional currency has no exchange difference', function (): void {
    Event::dispatch(fxrlAllocation($this, 'alloc-same', ['documentCurrency' => 'EUR', 'documentRate' => '1.000000', 'paymentRate' => '1.000000']));

    expect(RealizedFxDifference::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(JournalEntry::withoutGlobalScopes()->where('source_document_type', 'currency_realized_fx')->count())->toBe(0);
});

test('a settlement at the document rate is recorded as neutral and posts nothing', function (): void {
    Event::dispatch(fxrlAllocation($this, 'alloc-neutral', ['paymentRate' => '0.800000']));

    $record = RealizedFxDifference::query()->withoutGlobalScopes()->where('allocation_id', 'alloc-neutral')->sole();

    expect($record->result->value)->toBe('neutral')
        ->and((string) $record->difference)->toBe('0.00')
        ->and($record->journal_entry_id)->toBeNull();
});

test('a settlement whose ledger entry already carries the difference is only recorded', function (): void {
    Event::dispatch(fxrlAllocation($this, 'alloc-ledger', ['ledgerPostedDifference' => true]));

    $record = RealizedFxDifference::query()->withoutGlobalScopes()->where('allocation_id', 'alloc-ledger')->sole();

    expect($record->ledger_posted)->toBeTrue()
        ->and($record->journal_entry_id)->toBeNull()
        ->and((string) $record->effect)->toBe('33.33')
        ->and(JournalEntry::withoutGlobalScopes()->where('source_document_type', 'currency_realized_fx')->count())->toBe(0);
});

test('a company without exchange gain and loss accounts refuses the posting and keeps nothing', function (): void {
    $event = fxrlAllocation($this, 'alloc-noaccount', ['tenantId' => (string) $this->otherTenant->id]);

    expect(fn () => app(TenantContext::class)->runWithTenant($this->otherTenant, fn () => Event::dispatch($event)))->toThrow(CurrencyDomainException::class)
        ->and(RealizedFxDifference::query()->withoutGlobalScopes()->where('allocation_id', 'alloc-noaccount')->count())->toBe(0);
});

test('the same allocation id of two companies is two records', function (): void {
    Event::dispatch(fxrlAllocation($this, 'alloc-shared', ['ledgerPostedDifference' => true]));
    app(TenantContext::class)->runWithTenant($this->otherTenant, fn () => Event::dispatch(fxrlAllocation($this, 'alloc-shared', ['tenantId' => (string) $this->otherTenant->id, 'ledgerPostedDifference' => true])));

    expect(RealizedFxDifference::query()->withoutGlobalScopes()->where('allocation_id', 'alloc-shared')->count())->toBe(2);
});
