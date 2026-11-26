<?php

declare(strict_types=1);

use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Treasury\Currency\Application\Handlers\SyncExchangeRatesHandler;
use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Enums\RevaluationLineType;
use Modules\Treasury\Currency\Domain\Events\ExchangeRatesSynchronized;
use Modules\Treasury\Currency\Infrastructure\Jobs\SyncDailyRatesJob;
use Modules\Treasury\Currency\Infrastructure\Jobs\SyncEstvMonthlyRatesJob;
use Modules\Treasury\Currency\Infrastructure\Listeners\EvaluateRateMovementAlerts;
use Modules\Treasury\Currency\Infrastructure\Notifications\ExchangeRateMovementNotification;
use Modules\Treasury\Currency\Infrastructure\Notifications\ExchangeRateSyncFailedNotification;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencyPositionProvider;
use Modules\Treasury\Currency\Infrastructure\Persistence\ExchangeRateSyncRun;
use Modules\Treasury\Currency\Infrastructure\Providers\FakeRateProvider;
use Modules\Treasury\Currency\Infrastructure\Providers\RateProviderRegistry;
use Tests\Support\Currency\FxRates;

require_once __DIR__.'/CurrencySupport.php';

uses(RefreshDatabase::class);

/**
 * The synchronisation of the official rates with fake providers (no network): the runs, the rates and the derived cross rates it
 * stores, idempotence and versions, the failure that stores nothing and tells finance, the 409 of a running provider, the
 * scheduled jobs and the movement alert.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 17:00:00'));
    fxSetUp($this);
    ExchangeRate::query()->withoutGlobalScopes()->delete();
});

/**
 * @return list<array{0: string, 1: string, 2: string, 3: string}>
 */
function fxEcbDay(string $day = '2026-11-24', string $chf = '0.9412'): array
{
    return [['EUR', 'CHF', $chf, $day], ['EUR', 'USD', '1.056', $day], ['EUR', 'GBP', '0.86', $day]];
}

test('update now queues a run per provider, stores the official rates and derives the cross rates', function (): void {
    fxFakeProviders(ecb: fxEcbDay(), snb: [['USD', 'CHF', '0.8914', '2026-11-24']]);

    $response = $this->postJson('/api/v1/currencies/rates/sync')->assertStatus(202);
    $runs = collect($response->json('data.runs'))->keyBy('provider');

    expect($runs->keys()->sort()->values()->all())->toBe(['ecb', 'snb'])
        ->and($runs['ecb']['status'])->toBe('succeeded')
        ->and($runs['ecb']['trigger'])->toBe('manual')
        ->and($runs['ecb']['rates_count'])->toBe(3)
        ->and($runs['ecb']['derived_count'])->toBe(9)
        ->and($runs['ecb']['latest_rate_date'])->toBe('2026-11-24')
        ->and($runs['ecb']['error_message'])->toBeNull()
        ->and($runs['ecb']['started_at'])->not->toBeNull()
        ->and($runs['ecb']['finished_at'])->not->toBeNull()
        ->and($runs['snb']['status'])->toBe('succeeded')
        ->and($runs['snb']['rates_count'])->toBe(1)
        ->and($runs['snb']['derived_count'])->toBe(1);

    $official = ExchangeRate::query()->withoutGlobalScopes()->whereNull('tenant_id');

    expect((clone $official)->where('rate_source', 'ecb')->where('is_derived', false)->count())->toBe(3)
        ->and((clone $official)->where('rate_source', 'ecb')->where('is_derived', true)->count())->toBe(9)
        ->and((string) (clone $official)->where('source', 'CHF')->where('target', 'USD')->where('rate_source', 'ecb')->value('rate'))->toBe('1.121972')
        ->and((string) (clone $official)->where('source', 'EUR')->where('target', 'CHF')->where('is_derived', false)->value('rate'))->toBe('0.941200')
        ->and((clone $official)->where('rate_source', 'snb')->count())->toBe(2)
        ->and(ExchangeRateSyncRun::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(2);

    // The rates are official: every company reads them.
    treasuryActor($this, 'accountant', $this->otherTenant);

    expect($this->getJson('/api/v1/currencies/rates/resolve?base=EUR&quote=CHF&date=2026-11-24')->assertOk()->json('data.rate'))->toBe('0.941200')
        ->and($this->getJson('/api/v1/currencies/rates/resolve?base=CHF&quote=USD&date=2026-11-24')->assertOk()->json('data.is_derived'))->toBeTrue();
});

test('running the same feed again changes nothing, a new value is a new version of the official rate', function (): void {
    fxFakeProviders(ecb: fxEcbDay());
    $this->postJson('/api/v1/currencies/rates/sync', ['providers' => ['ecb']])->assertStatus(202);
    $before = ExchangeRate::query()->withoutGlobalScopes()->count();

    $this->postJson('/api/v1/currencies/rates/sync', ['providers' => ['ecb']])->assertStatus(202);

    expect(ExchangeRate::query()->withoutGlobalScopes()->count())->toBe($before)
        ->and(ExchangeRate::query()->withoutGlobalScopes()->whereNotNull('superseded_by')->count())->toBe(0);

    // The ECB corrects the franc rate of the same day.
    fxFakeProviders(ecb: fxEcbDay(chf: '0.9500'));
    $this->postJson('/api/v1/currencies/rates/sync', ['providers' => ['ecb']])->assertStatus(202);

    $versions = ExchangeRate::query()->withoutGlobalScopes()->where('source', 'EUR')->where('target', 'CHF')->where('rate_source', 'ecb')->orderBy('version')->get();

    expect($versions)->toHaveCount(2)
        ->and((string) $versions[0]->rate)->toBe('0.941200')
        ->and($versions[0]->superseded_by)->toBe($versions[1]->id)
        ->and((string) $versions[1]->rate)->toBe('0.950000')
        ->and($versions[1]->superseded_by)->toBeNull()
        ->and($this->getJson('/api/v1/currencies/rates/resolve?base=EUR&quote=CHF&date=2026-11-24')->json('data.rate'))->toBe('0.950000');
});

test('a failed sync marks the run failed, stores no rate, and tells the finance team', function (): void {
    fxFakeProviders(ecb: [], snb: [], ecbFailure: 'ECB feed answered HTTP 503.');

    $run = $this->postJson('/api/v1/currencies/rates/sync', ['providers' => ['ecb']])->assertStatus(202)->json('data.runs.0');

    expect($run['status'])->toBe('failed')
        ->and($run['error_message'])->toContain('503')
        ->and($run['rates_count'])->toBe(0)
        // No synthetic rate stands in for the feed that failed.
        ->and(ExchangeRate::query()->withoutGlobalScopes()->count())->toBe(0);

    $notification = $this->actor->notifications()->get()->first();

    expect($notification)->not->toBeNull()
        ->and($notification->type)->toBe(ExchangeRateSyncFailedNotification::class)
        ->and($notification->data['title_key'])->toBe(ExchangeRateSyncFailedNotification::TitleKey)
        ->and($notification->data['provider'])->toBe('ECB')
        ->and($notification->data['error'])->toContain('503')
        // A plain member of the company is not told.
        ->and($this->member->notifications()->count())->toBe(0);
});

test('a failed provider does not stop the other one', function (): void {
    fxFakeProviders(ecb: [], snb: [['USD', 'CHF', '0.8914', '2026-11-24']], ecbFailure: 'ECB feed is empty.');

    $runs = collect($this->postJson('/api/v1/currencies/rates/sync')->assertStatus(202)->json('data.runs'))->keyBy('provider');

    expect($runs['ecb']['status'])->toBe('failed')
        ->and($runs['snb']['status'])->toBe('succeeded')
        ->and(ExchangeRate::query()->withoutGlobalScopes()->where('rate_source', 'snb')->exists())->toBeTrue()
        ->and(ExchangeRate::query()->withoutGlobalScopes()->where('rate_source', 'ecb')->exists())->toBeFalse();
});

test('a provider already queued or running refuses the trigger with 409, a lost run does not', function (): void {
    fxFakeProviders(ecb: fxEcbDay(), snb: [['USD', 'CHF', '0.8914', '2026-11-24']]);
    DB::table('exchange_rate_sync_runs')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => null, 'provider' => 'ecb', 'status' => 'running', 'trigger' => 'scheduled',
        'created_at' => now()->subMinutes(2), 'updated_at' => now(),
    ]);

    $this->postJson('/api/v1/currencies/rates/sync', ['providers' => ['ecb']])->assertStatus(409)->assertJsonPath('code', 'sync_already_running');
    $this->postJson('/api/v1/currencies/rates/sync')->assertStatus(409);
    $this->postJson('/api/v1/currencies/rates/sync', ['providers' => ['snb']])->assertStatus(202);

    DB::table('exchange_rate_sync_runs')->update(['created_at' => now()->subHours(2)]);

    $this->postJson('/api/v1/currencies/rates/sync', ['providers' => ['ecb']])->assertStatus(202);
});

test('the sync request validates its providers', function (): void {
    $this->postJson('/api/v1/currencies/rates/sync', ['providers' => ['reuters']])->assertStatus(422)->assertJsonValidationErrors(['providers.0']);
    $this->postJson('/api/v1/currencies/rates/sync', ['providers' => []])->assertStatus(422);
});

test('the run history shows the scheduled runs of the platform and the own runs, not those of another company', function (): void {
    $insert = static fn (?string $tenantId, string $provider): bool => DB::table('exchange_rate_sync_runs')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'provider' => $provider, 'status' => 'succeeded', 'trigger' => $tenantId === null ? 'scheduled' : 'manual',
        'rates_count' => 3, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $insert(null, 'ecb');
    $insert((string) $this->tenant->id, 'snb');
    $insert((string) $this->otherTenant->id, 'estv');

    $runs = $this->getJson('/api/v1/currencies/sync-runs')->assertOk()->json('data');
    $member = (function (): array {
        treasuryActAs($this, $this->member);

        return $this->getJson('/api/v1/currencies/sync-runs')->assertOk()->json('data');
    })->call($this);

    expect(collect($runs)->pluck('provider')->sort()->values()->all())->toBe(['ecb', 'snb'])
        ->and($runs[0])->toHaveKeys(['id', 'provider', 'status', 'trigger', 'started_at', 'finished_at', 'rates_count', 'derived_count', 'error_message'])
        ->and($member)->toHaveCount(2);
});

test('the scheduled daily job runs both providers as platform runs', function (): void {
    fxFakeProviders(ecb: fxEcbDay(), snb: [['USD', 'CHF', '0.8914', '2026-11-24']]);

    (new SyncDailyRatesJob)->handle(app(SyncExchangeRatesHandler::class));

    $runs = ExchangeRateSyncRun::query()->withoutGlobalScopes()->orderBy('provider')->get();

    expect($runs)->toHaveCount(2)
        ->and($runs->pluck('tenant_id')->filter()->all())->toBe([])
        ->and($runs->pluck('trigger')->map(fn ($trigger) => $trigger->value)->unique()->all())->toBe(['scheduled'])
        ->and($runs->pluck('status')->map(fn ($status) => $status->value)->unique()->all())->toBe(['succeeded']);

    // Every company sees the scheduled runs.
    expect($this->getJson('/api/v1/currencies/sync-runs')->json('data'))->toHaveCount(2);
});

test('a scheduled failure is announced to the finance team of every company', function (): void {
    fxFakeProviders(ecb: [], snb: [['USD', 'CHF', '0.8914', '2026-11-24']], ecbFailure: 'ECB feed unreachable.');
    $swissOwner = treasuryActor($this, 'owner', $this->swiss);

    (new SyncDailyRatesJob)->handle(app(SyncExchangeRatesHandler::class));

    expect($this->actor->notifications()->count())->toBe(1)
        ->and($swissOwner->notifications()->count())->toBe(1)
        ->and($this->member->notifications()->count())->toBe(0);
});

test('the ESTV job stores the monthly average for Swiss VAT on the first day of the month, without cross rates', function (): void {
    app()->instance(RateProviderRegistry::class, new RateProviderRegistry([
        new FakeRateProvider(RateSource::Estv, [FxRates::quote('EUR', 'CHF', '0.945', '2026-11-01', RateSource::Estv, RateType::VatEstvMonthly), FxRates::quote('USD', 'CHF', '0.89', '2026-11-01', RateSource::Estv, RateType::VatEstvMonthly)]),
    ]));

    (new SyncEstvMonthlyRatesJob)->handle(app(SyncExchangeRatesHandler::class));

    $rows = ExchangeRate::query()->withoutGlobalScopes()->where('rate_type', 'vat_estv_monthly')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('rate_source')->unique()->all())->toBe(['estv'])
        ->and($rows->pluck('is_derived')->unique()->all())->toBe([false])
        ->and(ExchangeRate::query()->withoutGlobalScopes()->where('rate_type', 'mid')->count())->toBe(0);

    treasuryActor($this, 'accountant', $this->swiss);
    $resolved = $this->getJson('/api/v1/currencies/rates/resolve?base=EUR&quote=CHF&date=2026-11-27&rate_type=vat_estv_monthly')->assertOk()->json('data');

    expect($resolved['rate'])->toBe('0.945000')
        ->and($resolved['valid_on'])->toBe('2026-11-01')
        ->and($resolved['source'])->toBe('estv');
});

test('a currency with open exposure that moves more than the threshold in a day alerts the finance team once', function (): void {
    fxStoreRate('EUR', 'USD', '1.000000', '2026-11-23');
    fxStoreRate('EUR', 'USD', '1.060000', '2026-11-24');

    $positions = Mockery::mock(CurrencyPositionProvider::class);
    $positions->shouldReceive('positions')->andReturn([['line_type' => RevaluationLineType::Receivable, 'currency' => 'USD', 'reference' => null, 'account_id' => null, 'currency_balance' => '100.00', 'book_value' => '90.00']]);
    app()->instance(CurrencyPositionProvider::class, $positions);

    $event = new ExchangeRatesSynchronized('run-1', 'ecb', 3, 0, '2026-11-24', ['EUR', 'USD']);
    app(EvaluateRateMovementAlerts::class)->handle($event);
    app(EvaluateRateMovementAlerts::class)->handle($event);

    $notification = $this->actor->notifications()->get()->first();

    expect($this->actor->notifications()->count())->toBe(1)
        ->and($notification->type)->toBe(ExchangeRateMovementNotification::class)
        ->and($notification->data['currency'])->toBe('USD')
        ->and($notification->data['functional_currency'])->toBe('EUR')
        ->and($notification->data['change_percent'])->toBe('-5.66')
        ->and($notification->data['date'])->toBe('2026-11-24')
        ->and($this->member->notifications()->count())->toBe(0);
});

test('a movement under the company threshold alerts nobody', function (): void {
    fxStoreRate('EUR', 'USD', '1.000000', '2026-11-23');
    fxStoreRate('EUR', 'USD', '1.060000', '2026-11-24');
    $this->putJson('/api/v1/settings/currencies', ['active_currencies' => ['EUR', 'USD'], 'alert_threshold_percent' => '10', 'unrealized_gain_treatment' => 'recognise'])->assertOk();

    $positions = Mockery::mock(CurrencyPositionProvider::class);
    $positions->shouldReceive('positions')->andReturn([['line_type' => RevaluationLineType::Bank, 'currency' => 'USD', 'reference' => 'IBAN', 'account_id' => null, 'currency_balance' => '10.00', 'book_value' => '9.00']]);
    app()->instance(CurrencyPositionProvider::class, $positions);

    app(EvaluateRateMovementAlerts::class)->handle(new ExchangeRatesSynchronized('run-1', 'ecb', 3, 0, '2026-11-24'));

    expect($this->actor->notifications()->count())->toBe(0);
});
