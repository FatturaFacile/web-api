<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Exceptions\RateProviderException;
use Modules\Treasury\Currency\Infrastructure\Providers\EcbRateProvider;
use Modules\Treasury\Currency\Infrastructure\Providers\EstvMonthlyRateProvider;
use Modules\Treasury\Currency\Infrastructure\Providers\FakeRateProvider;
use Modules\Treasury\Currency\Infrastructure\Providers\RateProviderRegistry;
use Modules\Treasury\Currency\Infrastructure\Providers\SnbRateProvider;
use Tests\Support\Currency\FxRates;

/**
 * The official feeds with the network faked: schema validation, timeouts and retries, and the refusal (never built-in rates) of
 * a feed that cannot be fetched or validated. No test reaches the real ECB, SNB or ESTV.
 */
function ecbFeed(string $cubes = '<Cube currency="USD" rate="1.0560"/><Cube currency="CHF" rate="0.9412"/><Cube currency="JPY" rate="163.20"/>', string $day = '2026-11-24'): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><gesmes:subject>Reference rates</gesmes:subject><Cube><Cube time="'.$day.'">'.$cubes.'</Cube></Cube></gesmes:Envelope>';
}

beforeEach(function (): void {
    Sleep::fake();
    config([
        'currency_fx.providers.ecb.url' => 'https://ecb.test/eurofxref-daily.xml',
        'currency_fx.providers.snb.daily_url' => 'https://snb.test/daily',
        'currency_fx.providers.snb.monthly_url' => 'https://snb.test/monthly',
        'currency_fx.providers.estv.url' => 'https://estv.test/avgmonth',
    ]);
});

test('the ECB feed is parsed into EUR-based mid rates', function (): void {
    Http::fake(['ecb.test/*' => Http::response(ecbFeed(), 200)]);

    $quotes = (new EcbRateProvider)->fetch();

    expect($quotes)->toHaveCount(3)
        ->and($quotes[0]->pair->key())->toBe('EUR/USD')
        ->and($quotes[0]->rate->value)->toBe('1.056000')
        ->and($quotes[0]->validOn)->toBe('2026-11-24')
        ->and($quotes[0]->type)->toBe(RateType::Mid)
        ->and($quotes[0]->source)->toBe(RateSource::Ecb)
        ->and($quotes[2]->rate->value)->toBe('163.200000');
});

test('the ECB feed skips malformed cubes and refuses a feed without a valid rate', function (): void {
    $provider = new EcbRateProvider;

    $quotes = $provider->parse(ecbFeed('<Cube currency="USD" rate="1.0560"/><Cube currency="XX" rate="2"/><Cube currency="GBP" rate="0"/><Cube currency="SEK" rate="abc"/><Cube currency="EUR" rate="1"/>'));

    expect($quotes)->toHaveCount(1)
        ->and(fn () => $provider->parse(ecbFeed('<Cube currency="GBP" rate="-1"/>')))->toThrow(RateProviderException::class)
        ->and(fn () => $provider->parse('<html>maintenance</html>'))->toThrow(RateProviderException::class)
        ->and(fn () => $provider->parse('not xml at all'))->toThrow(RateProviderException::class)
        ->and(fn () => $provider->parse(ecbFeed('<Cube currency="USD" rate="1.05"/>', 'yesterday')))->toThrow(RateProviderException::class);
});

test('a failing feed is retried with back-off and then refused, with no built-in rates', function (): void {
    Http::fake(['ecb.test/*' => Http::response('Service unavailable', 503)]);

    expect(fn () => (new EcbRateProvider)->fetch())->toThrow(RateProviderException::class, 'HTTP 503');

    Http::assertSentCount(4);
});

test('an empty answer is refused', function (): void {
    Http::fake(['ecb.test/*' => Http::response('   ', 200)]);

    expect(fn () => (new EcbRateProvider)->fetch())->toThrow(RateProviderException::class, 'empty');
});

test('a feed that is unreachable is refused', function (): void {
    Http::fake(['ecb.test/*' => static fn () => throw new ConnectionException('Connection timed out')]);

    expect(fn () => (new EcbRateProvider)->fetch())->toThrow(RateProviderException::class);
});

test('the SNB daily cube is parsed per unit and the month-end cube becomes closing rates', function (): void {
    $daily = "\"CubeId\";\"devkua\"\n\"PublishingDate\";\"2026-11-24 14:30\"\n\n\"Date\";\"D0\";\"Value\"\n\"2026-11-20\";\"EUR1\";\"0.9412\"\n\"2026-11-20\";\"USD1\";\"0.8914\"\n\"2026-11-20\";\"JPY100\";\"0.5700\"\n\"2026-11-20\";\"CHF1\";\"1\"\n\"2026-11-20\";\"GBP1\";\"n/a\"\n";
    $monthly = "\"Date\";\"D0\";\"Value\"\n\"2026-10\";\"EUR1\";\"0.9350\"\n";
    Http::fake(['snb.test/daily*' => Http::response($daily, 200), 'snb.test/monthly*' => Http::response($monthly, 200)]);

    $quotes = (new SnbRateProvider)->fetch();
    $byKey = [];

    foreach ($quotes as $quote) {
        $byKey[$quote->pair->key().'|'.$quote->type->value] = $quote;
    }

    expect($quotes)->toHaveCount(4)
        ->and($byKey['EUR/CHF|mid']->rate->value)->toBe('0.941200')
        ->and($byKey['EUR/CHF|mid']->validOn)->toBe('2026-11-20')
        ->and($byKey['JPY/CHF|mid']->rate->value)->toBe('0.005700')
        ->and($byKey['EUR/CHF|closing']->rate->value)->toBe('0.935000')
        ->and($byKey['EUR/CHF|closing']->validOn)->toBe('2026-10-31')
        ->and($byKey['EUR/CHF|closing']->source)->toBe(RateSource::Snb);
});

test('the SNB monthly cube is optional', function (): void {
    $daily = '"Date";"D0";"Value"
"2026-11-20";"EUR1";"0.9412"
';
    Http::fake(['snb.test/daily*' => Http::response($daily, 200), 'snb.test/monthly*' => Http::response('', 500)]);

    expect((new SnbRateProvider)->fetch())->toHaveCount(1);
});

test('the SNB daily cube is not optional and a cube without a valid rate is refused', function (): void {
    Http::fake(['snb.test/*' => Http::response('', 500)]);

    expect(fn () => (new SnbRateProvider)->fetch())->toThrow(RateProviderException::class)
        ->and(fn () => (new SnbRateProvider)->parse('Date;D0;Value
', RateType::Mid))->toThrow(RateProviderException::class);
});

test('the ESTV monthly average is stored on the first day of its month', function (): void {
    $this->travelTo('2026-11-26 10:00:00');
    $xml = '<rates><month>2026-10</month><currency><code>EUR</code><unit>1</unit><rate>0.9450</rate></currency><currency><code>JPY</code><unit>100</unit><rate>0.6100</rate></currency><currency><code>XXX</code><unit>0</unit><rate>1</rate></currency></rates>';
    // October is published; the running month is not yet.
    Http::fake(['estv.test/*' => static fn ($request) => str_contains($request->url(), 'month=2026-10') ? Http::response($xml, 200) : Http::response('', 404)]);

    $quotes = (new EstvMonthlyRateProvider)->fetch();

    expect($quotes)->toHaveCount(2)
        ->and($quotes[0]->pair->key())->toBe('EUR/CHF')
        ->and($quotes[0]->rate->value)->toBe('0.945000')
        ->and($quotes[0]->validOn)->toBe('2026-10-01')
        ->and($quotes[0]->type)->toBe(RateType::VatEstvMonthly)
        ->and($quotes[0]->source)->toBe(RateSource::Estv)
        ->and($quotes[1]->rate->value)->toBe('0.006100');
});

test('an ESTV feed without a month or without a valid rate is refused', function (): void {
    $provider = new EstvMonthlyRateProvider;

    expect(fn () => $provider->parse('<rates><currency><code>EUR</code><rate>0.945</rate></currency></rates>'))->toThrow(RateProviderException::class, 'month')
        ->and(fn () => $provider->parse('<rates month="2026-11"><currency><code>EUR</code><rate>-1</rate></currency></rates>'))->toThrow(RateProviderException::class)
        ->and(fn () => $provider->parse('nope'))->toThrow(RateProviderException::class)
        ->and($provider->parse('<rates month="2026-11"><currency code="EUR" unit="1" rate="0.945"/></rates>'))->toHaveCount(1);
});

test('the registry hands out the providers by source and tests swap in a fake', function (): void {
    $registry = new RateProviderRegistry([new EcbRateProvider]);

    expect($registry->has(RateSource::Ecb))->toBeTrue()
        ->and($registry->has(RateSource::Snb))->toBeFalse()
        ->and(fn () => $registry->get(RateSource::Snb))->toThrow(InvalidArgumentException::class);

    $registry->swap(new FakeRateProvider(RateSource::Ecb, [FxRates::quote('EUR', 'CHF', '0.9412', '2026-11-24')]));

    expect($registry->get(RateSource::Ecb)->fetch())->toHaveCount(1)
        ->and($registry->sources())->toBe([RateSource::Ecb]);

    $registry->swap(new FakeRateProvider(RateSource::Ecb, failure: 'feed down'));

    expect(fn () => $registry->get(RateSource::Ecb)->fetch())->toThrow(RateProviderException::class, 'feed down');
});
