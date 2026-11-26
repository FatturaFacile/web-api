<?php

declare(strict_types=1);

use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Services\InvoiceExchangeRateResolver;
use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Exceptions\ExchangeRateMissingException;

require_once __DIR__.'/CurrencySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00'));
    fxSetUp($this);
    // The reference data of the test database may hold rates: these tests start from an empty table.
    ExchangeRate::query()->withoutGlobalScopes()->delete();
});

/**
 * Exchange rates API (Mod 003): the list the company sees, the rate that applies on a day, manual and contract rates, versions,
 * tenant isolation, the audit export, the document emission, and the way SQLite and PostgreSQL store and compare days.
 * 2026-11-20 is a Friday.
 */
test('the list shows the official rates and the own rates of the company, never another company\'s', function (): void {
    fxStoreRate('EUR', 'CHF', '0.941200', '2026-11-20');
    fxStoreRate('EUR', 'CHF', '0.930000', '2026-11-20', (string) $this->tenant->id, source: RateSource::Manual);
    fxStoreRate('EUR', 'CHF', '0.500000', '2026-11-20', (string) $this->otherTenant->id, source: RateSource::Manual);

    $rates = $this->getJson('/api/v1/currencies/rates?base=EUR&quote=CHF')->assertOk()->json('data');

    expect($rates)->toHaveCount(2)
        ->and(collect($rates)->pluck('rate')->sort()->values()->all())->toBe(['0.930000', '0.941200'])
        ->and(collect($rates)->pluck('tenant_id')->filter()->values()->all())->toBe([(string) $this->tenant->id])
        ->and($rates[0]['valid_on'])->toBe('2026-11-20')
        ->and($rates[0])->toHaveKeys(['id', 'base', 'quote', 'rate', 'rate_type', 'source', 'valid_on', 'is_derived', 'is_contract', 'superseded_by', 'reason', 'contract_reference']);
});

test('the list filters by type and period and paginates', function (): void {
    fxStoreRate('EUR', 'CHF', '0.941200', '2026-11-20');
    fxStoreRate('EUR', 'CHF', '0.940000', '2026-11-19');
    fxStoreRate('EUR', 'CHF', '0.945000', '2026-11-01', type: RateType::VatEstvMonthly, source: RateSource::Estv);

    $page = $this->getJson('/api/v1/currencies/rates?from=2026-11-19&to=2026-11-20&per_page=1')->assertOk();

    expect($page->json('data'))->toHaveCount(1)
        ->and($page->json('meta.total'))->toBe(2)
        ->and($page->json('meta.last_page'))->toBe(2)
        ->and($this->getJson('/api/v1/currencies/rates?rate_type=vat_estv_monthly')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/currencies/rates?to=2026-11-10&from=2026-11-20')->status())->toBe(422);
});

test('resolve answers the rate of the day and, on a weekend, the rate of the last working day', function (): void {
    fxStoreRate('EUR', 'CHF', '0.941200', '2026-11-20');

    $day = $this->getJson('/api/v1/currencies/rates/resolve?base=EUR&quote=CHF&date=2026-11-20')->assertOk()->json('data');
    $weekend = $this->getJson('/api/v1/currencies/rates/resolve?base=EUR&quote=CHF&date=2026-11-22')->assertOk()->json('data');

    expect($day['rate'])->toBe('0.941200')
        ->and($day['is_fallback_date'])->toBeFalse()
        ->and($weekend['rate'])->toBe('0.941200')
        ->and($weekend['valid_on'])->toBe('2026-11-20')
        ->and($weekend['requested_date'])->toBe('2026-11-22')
        ->and($weekend['is_fallback_date'])->toBeTrue()
        ->and($weekend['is_derived'])->toBeFalse()
        ->and($weekend['is_contract'])->toBeFalse();
});

test('resolve answers an inverse and a cross rate flagged derived, and 1.000000 for the same currency', function (): void {
    fxStoreRate('EUR', 'CHF', '0.941200', '2026-11-20');
    fxStoreRate('EUR', 'USD', '1.056000', '2026-11-20');

    $inverse = $this->getJson('/api/v1/currencies/rates/resolve?base=CHF&quote=EUR&date=2026-11-20')->assertOk()->json('data');
    $cross = $this->getJson('/api/v1/currencies/rates/resolve?base=USD&quote=CHF&date=2026-11-20')->assertOk()->json('data');
    $same = $this->getJson('/api/v1/currencies/rates/resolve?base=CHF&quote=CHF&date=2026-11-20')->assertOk()->json('data');

    expect($inverse['rate'])->toBe('1.062473')
        ->and($inverse['is_derived'])->toBeTrue()
        ->and($cross['rate'])->toBe('0.891288')
        ->and($cross['is_derived'])->toBeTrue()
        ->and($same['rate'])->toBe('1.000000');
});

test('beyond five days resolve is a blocking 422 exchange_rate_missing and never an invented rate', function (): void {
    fxStoreRate('EUR', 'CHF', '0.941200', '2026-11-20');

    $edge = $this->getJson('/api/v1/currencies/rates/resolve?base=EUR&quote=CHF&date=2026-11-25')->assertOk();
    $response = $this->getJson('/api/v1/currencies/rates/resolve?base=EUR&quote=CHF&date=2026-11-26')->assertStatus(422);

    expect($edge->json('data.valid_on'))->toBe('2026-11-20')
        ->and($response->json('success'))->toBeFalse()
        ->and($response->json('code'))->toBe('exchange_rate_missing')
        ->and($response->json('errors.exchange_rate'))->toHaveCount(1);

    $this->getJson('/api/v1/currencies/rates/resolve?base=EUR&quote=JPY&date=2026-11-20')->assertStatus(422)->assertJsonPath('code', 'exchange_rate_missing');
});

test('resolve validates its parameters', function (): void {
    $this->getJson('/api/v1/currencies/rates/resolve?base=EUR&date=2026-11-20')->assertStatus(422)->assertJsonValidationErrors(['quote']);
    $this->getJson('/api/v1/currencies/rates/resolve?base=EUR&quote=CHF&date=20-11-2026')->assertStatus(422)->assertJsonValidationErrors(['date']);
    $this->getJson('/api/v1/currencies/rates/resolve?base=EUR&quote=CHF&date=2026-11-20&rate_type=bogus')->assertStatus(422)->assertJsonValidationErrors(['rate_type']);
});

test('a manual rate is stored for the company only and wins over the official rate for it', function (): void {
    fxStoreRate('EUR', 'CHF', '0.941200', '2026-11-20');

    $response = $this->postJson('/api/v1/currencies/rates', ['base' => 'eur', 'quote' => 'chf', 'rate' => '0.930000', 'valid_on' => '2026-11-20'])->assertCreated();

    expect($response->json('data.rate'))->toBe('0.930000')
        ->and($response->json('data.source'))->toBe('manual')
        ->and($response->json('data.tenant_id'))->toBe((string) $this->tenant->id)
        ->and($response->json('data.rate_type'))->toBe('mid')
        ->and($this->getJson('/api/v1/currencies/rates/resolve?base=EUR&quote=CHF&date=2026-11-20')->json('data.rate'))->toBe('0.930000');

    // Another company still sees the official rate and not this one.
    treasuryActor($this, 'accountant', $this->otherTenant);

    expect($this->getJson('/api/v1/currencies/rates/resolve?base=EUR&quote=CHF&date=2026-11-20')->json('data.rate'))->toBe('0.941200')
        ->and(collect($this->getJson('/api/v1/currencies/rates')->json('data'))->pluck('rate')->all())->toBe(['0.941200']);
});

test('a manual rate has at most six decimals, is positive and is for two different currencies', function (): void {
    $payload = ['base' => 'EUR', 'quote' => 'CHF', 'rate' => '0.941200', 'valid_on' => '2026-11-20'];

    $this->postJson('/api/v1/currencies/rates', ['rate' => '0.9412001'] + $payload)->assertStatus(422)->assertJsonValidationErrors(['rate']);
    $this->postJson('/api/v1/currencies/rates', ['rate' => '0'] + $payload)->assertStatus(422)->assertJsonValidationErrors(['rate']);
    $this->postJson('/api/v1/currencies/rates', ['rate' => '-1'] + $payload)->assertStatus(422)->assertJsonValidationErrors(['rate']);
    $this->postJson('/api/v1/currencies/rates', ['rate' => 'abc'] + $payload)->assertStatus(422)->assertJsonValidationErrors(['rate']);
    $this->postJson('/api/v1/currencies/rates', ['quote' => 'EUR'] + $payload)->assertStatus(422)->assertJsonValidationErrors(['quote']);
    $this->postJson('/api/v1/currencies/rates', ['valid_on' => '26/11/2026'] + $payload)->assertStatus(422)->assertJsonValidationErrors(['valid_on']);
    $this->postJson('/api/v1/currencies/rates', ['rate_type' => 'bogus'] + $payload)->assertStatus(422)->assertJsonValidationErrors(['rate_type']);

    expect(ExchangeRate::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('a contract rate needs a reason and is resolved by its reference with the contract flag', function (): void {
    fxStoreRate('EUR', 'CHF', '0.941200', '2026-11-20');
    $payload = ['base' => 'EUR', 'quote' => 'CHF', 'rate' => '0.920000', 'valid_on' => '2026-01-10', 'rate_type' => 'contract', 'contract_reference' => 'CNT-77'];

    $this->postJson('/api/v1/currencies/rates', $payload)->assertStatus(422)->assertJsonValidationErrors(['reason']);
    $created = $this->postJson('/api/v1/currencies/rates', $payload + ['reason' => 'Annual supply contract with Acme'])->assertCreated();

    $contract = $this->getJson('/api/v1/currencies/rates/resolve?base=EUR&quote=CHF&date=2026-11-20&contract_reference=CNT-77')->assertOk()->json('data');
    $plain = $this->getJson('/api/v1/currencies/rates/resolve?base=EUR&quote=CHF&date=2026-11-20')->assertOk()->json('data');

    expect($created->json('data.is_contract'))->toBeTrue()
        ->and($created->json('data.reason'))->toBe('Annual supply contract with Acme')
        ->and($contract['rate'])->toBe('0.920000')
        ->and($contract['is_contract'])->toBeTrue()
        ->and($plain['rate'])->toBe('0.941200')
        ->and($plain['is_contract'])->toBeFalse();
});

test('a correction is a new version and the old one points to it: a rate is never edited', function (): void {
    $payload = ['base' => 'EUR', 'quote' => 'CHF', 'valid_on' => '2026-11-20'];

    $first = $this->postJson('/api/v1/currencies/rates', $payload + ['rate' => '0.930000'])->assertCreated()->json('data');
    $same = $this->postJson('/api/v1/currencies/rates', $payload + ['rate' => '0.930000'])->assertCreated()->json('data');
    $second = $this->postJson('/api/v1/currencies/rates', $payload + ['rate' => '0.935000', 'reason' => 'typo'])->assertCreated()->json('data');

    $rows = ExchangeRate::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->orderBy('version')->get();

    expect($same['id'])->toBe($first['id'])
        ->and($rows)->toHaveCount(2)
        ->and((string) $rows[0]->rate)->toBe('0.930000')
        ->and($rows[0]->version)->toBe(1)
        ->and($rows[0]->superseded_by)->toBe($second['id'])
        ->and((string) $rows[1]->rate)->toBe('0.935000')
        ->and($rows[1]->version)->toBe(2)
        ->and($rows[1]->superseded_by)->toBeNull()
        ->and($second['version'])->toBe(2)
        ->and($this->getJson('/api/v1/currencies/rates?base=EUR')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/currencies/rates/resolve?base=EUR&quote=CHF&date=2026-11-20')->json('data.rate'))->toBe('0.935000');
});

test('a member reads the rates but cannot enter one or trigger the synchronisation', function (): void {
    fxStoreRate('EUR', 'CHF', '0.941200', '2026-11-20');
    treasuryActAs($this, $this->member);

    $this->getJson('/api/v1/currencies/rates')->assertOk();
    $this->getJson('/api/v1/currencies/rates/resolve?base=EUR&quote=CHF&date=2026-11-20')->assertOk();
    $this->postJson('/api/v1/currencies/rates', ['base' => 'EUR', 'quote' => 'CHF', 'rate' => '0.9', 'valid_on' => '2026-11-20'])->assertForbidden();
    $this->postJson('/api/v1/currencies/rates/sync')->assertForbidden();
});

test('an unauthenticated call is refused', function (): void {
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/currencies/rates')->assertUnauthorized();
});

test('the audit export lists every rate of the period, superseded versions included, as CSV and XLSX', function (): void {
    fxStoreRate('EUR', 'CHF', '0.941200', '2026-11-20');
    fxStoreRate('EUR', 'CHF', '0.930000', '2026-11-20', (string) $this->tenant->id, source: RateSource::Manual);
    fxStoreRate('EUR', 'CHF', '0.935000', '2026-11-20', (string) $this->tenant->id, source: RateSource::Manual);
    fxStoreRate('EUR', 'CHF', '0.500000', '2026-11-20', (string) $this->otherTenant->id, source: RateSource::Manual);
    fxStoreRate('EUR', 'USD', '1.056000', '2026-10-01');

    $csv = $this->get('/api/v1/currencies/rates/export?from=2026-11-01&to=2026-11-30');
    $csv->assertOk();
    $body = (string) $csv->getContent();

    expect($csv->headers->get('Content-Type'))->toContain('text/csv')
        ->and($csv->headers->get('Content-Disposition'))->toContain('exchange-rates-2026-11-01-2026-11-30.csv')
        ->and($body)->toContain('"EUR";"CHF";"0.941200";"mid";"ecb";"2026-11-20";"no";"1";"no";"official"')
        ->and($body)->toContain('"EUR";"CHF";"0.930000";"mid";"manual";"2026-11-20";"no";"1";"yes";"company"')
        ->and($body)->toContain('"EUR";"CHF";"0.935000";"mid";"manual";"2026-11-20";"no";"2";"no";"company"')
        ->and($body)->not->toContain('0.500000')
        ->and($body)->not->toContain('1.056000');

    $xlsx = $this->get('/api/v1/currencies/rates/export?from=2026-11-01&to=2026-11-30&format=xlsx')->assertOk();

    expect($xlsx->headers->get('Content-Type'))->toContain('spreadsheetml')
        ->and(substr((string) $xlsx->getContent(), 0, 2))->toBe('PK');

    $this->getJson('/api/v1/currencies/rates/export?from=2026-11-30&to=2026-11-01')->assertStatus(422);
});

test('a document in a foreign currency freezes the rate of its date, or of the five days before it', function (): void {
    fxStoreRate('EUR', 'USD', '1.250000', '2026-11-20');
    $resolver = app(InvoiceExchangeRateResolver::class);

    $invoice = new SalesInvoice;
    $invoice->forceFill(['tenant_id' => (string) $this->tenant->id, 'currency' => 'USD', 'date' => '2026-11-22']);
    $resolver->freeze($invoice);

    expect((string) $invoice->exchange_rate)->toBe('0.800000')
        ->and($invoice->exchange_rate_source)->toBe(InvoiceExchangeRateResolver::SOURCE_SERVICE)
        ->and($invoice->exchange_rate_date->toDateString())->toBe('2026-11-20');

    $same = new SalesInvoice;
    $same->forceFill(['tenant_id' => (string) $this->tenant->id, 'currency' => 'EUR', 'date' => '2026-11-22']);
    $resolver->freeze($same);

    expect((string) $same->exchange_rate)->toBe('1.000000')
        ->and($same->exchange_rate_source)->toBe(InvoiceExchangeRateResolver::SOURCE_ACCOUNTING_CURRENCY);

    $late = new SalesInvoice;
    $late->forceFill(['tenant_id' => (string) $this->tenant->id, 'currency' => 'USD', 'date' => '2026-11-26']);

    expect(fn () => $resolver->freeze($late))->toThrow(ExchangeRateMissingException::class);
});

test('the legacy rows of the exchange-rate table (source, target, rate, effective_date) are still read', function (): void {
    // A row written before the engine: no rate type, no source, no version.
    ExchangeRate::query()->withoutGlobalScopes()->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => null, 'source' => 'CHF', 'target' => 'EUR', 'rate' => '1.061008',
        'effective_date' => '2026-11-20', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $resolved = $this->getJson('/api/v1/currencies/rates/resolve?base=EUR&quote=CHF&date=2026-11-20')->assertOk()->json('data');
    $listed = $this->getJson('/api/v1/currencies/rates?base=CHF&quote=EUR')->assertOk()->json('data');

    expect($resolved['rate'])->toBe('0.942500')
        ->and($resolved['is_derived'])->toBeTrue()
        ->and($listed)->toHaveCount(1)
        ->and($listed[0]['rate_type'])->toBe('mid')
        ->and($listed[0]['version'])->toBe(1);
});

test('days are stored and compared as Y-m-d on SQLite and PostgreSQL alike', function (): void {
    // SQLite keeps whatever text it is given in a date column: a datetime string must still be found by its day.
    DB::table('exchange_rates')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => null, 'source' => 'EUR', 'target' => 'GBP', 'rate' => '0.860000',
        'effective_date' => '2026-11-20 00:00:00', 'rate_type' => 'mid', 'rate_source' => 'ecb', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->postJson('/api/v1/currencies/rates', ['base' => 'EUR', 'quote' => 'USD', 'rate' => '1.05', 'valid_on' => '2026-11-20'])->assertCreated();

    DB::flushQueryListeners();
    DB::enableQueryLog();

    $datetime = $this->getJson('/api/v1/currencies/rates/resolve?base=EUR&quote=GBP&date=2026-11-23')->assertOk()->json('data');
    $exact = $this->getJson('/api/v1/currencies/rates/resolve?base=EUR&quote=GBP&date=2026-11-20')->assertOk()->json('data');
    $sql = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_contains($query, 'exchange_rates'))->implode("\n");

    // The model writes the plain day, whatever the engine.
    $stored = DB::table('exchange_rates')->where('target', 'USD')->value('effective_date');

    expect($datetime['valid_on'])->toBe('2026-11-20')
        ->and($exact['valid_on'])->toBe('2026-11-20')
        ->and($exact['is_fallback_date'])->toBeFalse()
        ->and($stored)->toBe('2026-11-20')
        ->and($sql)->not->toBe('')
        // Nothing the engine runs is PostgreSQL-only: no ILIKE, no ::cast, no DISTINCT ON, no jsonb operators.
        ->and(strtolower($sql))->not->toContain('ilike')
        ->and($sql)->not->toContain('::')
        ->and(strtolower($sql))->not->toContain('distinct on')
        ->and($sql)->not->toContain('->>');
});
