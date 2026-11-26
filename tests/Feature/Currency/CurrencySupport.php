<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;
use Modules\Treasury\Currency\Infrastructure\Persistence\ExchangeRateRepository;
use Modules\Treasury\Currency\Infrastructure\Providers\FakeRateProvider;
use Modules\Treasury\Currency\Infrastructure\Providers\RateProviderRegistry;
use Tests\Support\Currency\FxRates;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

/**
 * Two companies (a euro one and a Swiss one, plus a second euro company for isolation checks) and an accountant of the first
 * signed in. `$this->member` is a plain member of the first company, `$this->swiss` its Swiss sibling.
 */
function fxSetUp(object $test): void
{
    $suffix = Str::upper(Str::random(5));
    $test->tenant = Tenant::create(['name' => 'Alfa '.$suffix, 'legal_name' => 'Alfa '.$suffix.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $test->otherTenant = Tenant::create(['name' => 'Beta '.$suffix, 'legal_name' => 'Beta '.$suffix.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $test->swiss = Tenant::create(['name' => 'Gamma '.$suffix, 'legal_name' => 'Gamma '.$suffix.' AG', 'country' => 'CH', 'default_currency' => 'CHF']);

    $test->actor = treasuryActor($test, 'accountant');
    $test->member = User::create(['name' => 'Member', 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $test->member->tenants()->attach($test->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member', 'permissions' => json_encode([])]);
}

/**
 * An official rate (no tenant) or a rate of a company, stored the way the engine stores it.
 */
function fxStoreRate(string $base, string $quote, string $rate, string $day, ?string $tenantId = null, RateType $type = RateType::Mid, RateSource $source = RateSource::Ecb, bool $derived = false, ?string $reason = null, ?string $contractReference = null): void
{
    app(ExchangeRateRepository::class)->record($tenantId, CurrencyPair::of($base, $quote), ExchangeRateValue::of($rate), $type, $source, $day, $derived, $reason, $contractReference);
}

/**
 * Replaces the providers by fakes: no feed is ever fetched.
 *
 * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $ecb  [base, quote, rate, day]
 * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $snb
 */
function fxFakeProviders(array $ecb = [], array $snb = [], ?string $ecbFailure = null, ?string $snbFailure = null): RateProviderRegistry
{
    $quotes = static fn (array $rows, RateSource $source): array => array_map(
        static fn (array $row): object => FxRates::quote($row[0], $row[1], $row[2], $row[3], $source),
        $rows,
    );

    $registry = new RateProviderRegistry([
        new FakeRateProvider(RateSource::Ecb, $quotes($ecb, RateSource::Ecb), $ecbFailure),
        new FakeRateProvider(RateSource::Snb, $quotes($snb, RateSource::Snb), $snbFailure),
        new FakeRateProvider(RateSource::Estv, []),
    ]);
    app()->instance(RateProviderRegistry::class, $registry);

    return $registry;
}
