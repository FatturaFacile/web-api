<?php

declare(strict_types=1);

use App\Contracts\Security\HostResolver;
use App\Exceptions\UnsafeOutboundUrlException;
use App\Services\Security\OutboundUrlGuard;
use Illuminate\Support\Facades\Http;
use Tests\Support\StaticHostResolver;

function outboundGuard(array $records = [], array $allowedHosts = [], ?string $fallback = StaticHostResolver::DEFAULT_ADDRESS): OutboundUrlGuard
{
    return new OutboundUrlGuard(new StaticHostResolver($records, $fallback), $allowedHosts);
}

function outboundRefusal(Closure $call): ?string
{
    try {
        $call();
    } catch (UnsafeOutboundUrlException $exception) {
        return $exception->reason;
    }

    return null;
}

test('public names and public literal addresses are accepted', function (string $url): void {
    expect(outboundGuard(['shop.example.com' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c']])->isPublicUrl($url))->toBeTrue();
})->with([
    'https://shop.example.com/wp-json',
    'https://shop.example.com:8080/api',
    'https://SHOP.EXAMPLE.COM./api',
    'https://8.8.8.8/',
    'https://[2606:4700:4700::1111]/',
]);

test('loopback, private, link-local, metadata and other reserved literals are refused', function (string $url): void {
    expect(outboundRefusal(fn () => outboundGuard()->inspectUrl($url)))->toBe(UnsafeOutboundUrlException::NON_PUBLIC_ADDRESS);
})->with([
    'loopback' => 'https://127.0.0.1/',
    'loopback range' => 'https://127.10.20.30/',
    'any' => 'https://0.0.0.0/',
    'private 10' => 'https://10.1.2.3/',
    'private 172' => 'https://172.16.0.1/',
    'private 192' => 'https://192.168.1.1/',
    'link-local metadata' => 'https://169.254.169.254/latest/meta-data/',
    'carrier-grade nat' => 'https://100.64.0.1/',
    'benchmarking' => 'https://198.18.0.1/',
    'documentation' => 'https://203.0.113.5/',
    'multicast' => 'https://224.0.0.1/',
    'reserved' => 'https://240.0.0.1/',
    'broadcast' => 'https://255.255.255.255/',
    'ipv6 loopback' => 'https://[::1]/',
    'ipv6 unspecified' => 'https://[::]/',
    'ipv6 unique local' => 'https://[fd00:ec2::254]/',
    'ipv6 link-local' => 'https://[fe80::1]/',
    'ipv6 site-local' => 'https://[fec0::1]/',
    'ipv6 multicast' => 'https://[ff02::1]/',
    'ipv4-mapped loopback' => 'https://[::ffff:127.0.0.1]/',
    'ipv4-mapped metadata' => 'https://[::ffff:a9fe:a9fe]/',
    'ipv4-compatible' => 'https://[::127.0.0.1]/',
    'nat64' => 'https://[64:ff9b::7f00:1]/',
    '6to4' => 'https://[2002:7f00:1::1]/',
    'teredo' => 'https://[2001:0:4136:e378:8000:63bf:3fff:fdd2]/',
    'ipv6 documentation' => 'https://[2001:db8::1]/',
]);

test('non canonical IPv4 spellings are refused before any lookup', function (string $url): void {
    expect(outboundRefusal(fn () => outboundGuard()->inspectUrl($url)))->toBe(UnsafeOutboundUrlException::INVALID_HOST);
})->with([
    'decimal' => 'https://2130706433/',
    'hex' => 'https://0x7f.1/',
    'hex dotted' => 'https://0x7f.0x0.0x0.0x1/',
    'octal' => 'https://0177.0.0.1/',
    'short' => 'https://127.1/',
    'short three parts' => 'https://10.0.1/',
    'five parts' => 'https://1.2.3.4.5/',
    'leading zero' => 'https://127.000.000.001/',
]);

test('names resolving to a private or metadata address are refused, even when only one record is private', function (): void {
    $guard = outboundGuard([
        '169.254.169.254.nip.io' => ['169.254.169.254'],
        'rebind.example.com' => ['93.184.215.14', '10.0.0.5'],
        'v6.example.com' => ['fd12:3456::1'],
        'mapped.example.com' => ['::ffff:127.0.0.1'],
    ]);

    expect(outboundRefusal(fn () => $guard->inspectUrl('https://169.254.169.254.nip.io/latest/meta-data/')))->toBe(UnsafeOutboundUrlException::NON_PUBLIC_ADDRESS)
        ->and(outboundRefusal(fn () => $guard->inspectUrl('https://rebind.example.com/')))->toBe(UnsafeOutboundUrlException::NON_PUBLIC_ADDRESS)
        ->and(outboundRefusal(fn () => $guard->inspectUrl('https://v6.example.com/')))->toBe(UnsafeOutboundUrlException::NON_PUBLIC_ADDRESS)
        ->and(outboundRefusal(fn () => $guard->inspectUrl('https://mapped.example.com/')))->toBe(UnsafeOutboundUrlException::NON_PUBLIC_ADDRESS);
});

test('internal names, unresolvable names and malformed URLs are refused', function (): void {
    $guard = outboundGuard(fallback: null);

    expect(outboundRefusal(fn () => $guard->inspectUrl('https://localhost/')))->toBe(UnsafeOutboundUrlException::NON_PUBLIC_ADDRESS)
        ->and(outboundRefusal(fn () => $guard->inspectUrl('https://localhost./')))->toBe(UnsafeOutboundUrlException::NON_PUBLIC_ADDRESS)
        ->and(outboundRefusal(fn () => $guard->inspectUrl('https://app.localhost/')))->toBe(UnsafeOutboundUrlException::NON_PUBLIC_ADDRESS)
        ->and(outboundRefusal(fn () => $guard->inspectUrl('https://metadata.google.internal/')))->toBe(UnsafeOutboundUrlException::NON_PUBLIC_ADDRESS)
        ->and(outboundRefusal(fn () => $guard->inspectUrl('https://printer.local/')))->toBe(UnsafeOutboundUrlException::NON_PUBLIC_ADDRESS)
        ->and(outboundRefusal(fn () => $guard->inspectUrl('https://does-not-exist.example/')))->toBe(UnsafeOutboundUrlException::UNRESOLVABLE_HOST)
        ->and(outboundRefusal(fn () => $guard->inspectUrl('ftp://example.com/')))->toBe(UnsafeOutboundUrlException::INVALID_URL)
        ->and(outboundRefusal(fn () => $guard->inspectUrl('file:///etc/passwd')))->toBe(UnsafeOutboundUrlException::INVALID_URL)
        ->and(outboundRefusal(fn () => $guard->inspectUrl('gopher://127.0.0.1:6379/_')))->toBe(UnsafeOutboundUrlException::INVALID_URL)
        ->and(outboundRefusal(fn () => $guard->inspectUrl('https:///path')))->toBe(UnsafeOutboundUrlException::INVALID_URL)
        ->and(outboundRefusal(fn () => $guard->inspectUrl('https://example.com\\@127.0.0.1/')))->toBe(UnsafeOutboundUrlException::INVALID_URL)
        ->and(outboundRefusal(fn () => $guard->inspectUrl('https://exa mple.com/')))->toBe(UnsafeOutboundUrlException::INVALID_URL)
        ->and(outboundRefusal(fn () => $guard->inspectUrl('https://[::1/')))->toBeIn([UnsafeOutboundUrlException::INVALID_URL, UnsafeOutboundUrlException::INVALID_HOST])
        ->and(outboundRefusal(fn () => $guard->inspectUrl('https://exa_mple!.com/')))->toBe(UnsafeOutboundUrlException::INVALID_HOST);
});

test('credentials in the URL do not hide the real host', function (): void {
    expect(outboundRefusal(fn () => outboundGuard()->inspectUrl('https://shop.example.com@127.0.0.1/')))->toBe(UnsafeOutboundUrlException::NON_PUBLIC_ADDRESS)
        ->and(outboundGuard()->isPublicUrl('https://user:pass@shop.example.com/'))->toBeTrue();
});

test('bare mail hosts are checked the same way', function (): void {
    $guard = outboundGuard(['imap.internal-corp.com' => ['192.168.10.2']]);

    expect($guard->isPublicHost('imap.example.com', 993))->toBeTrue()
        ->and($guard->isPublicHost('imap.internal-corp.com', 993))->toBeFalse()
        ->and($guard->isPublicHost('127.0.0.1', 993))->toBeFalse()
        ->and($guard->isPublicHost('[::1]', 993))->toBeFalse()
        ->and($guard->isPublicHost('2130706433', 993))->toBeFalse()
        ->and($guard->isPublicHost('imap.example.com', 0))->toBeFalse();
});

test('the resolved addresses are pinned for curl, literals and allow-listed hosts are not', function (): void {
    $guard = outboundGuard(['shop.example.com' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c']], ['dev-shop.test', '*.lan.test']);

    expect($guard->inspectUrl('https://shop.example.com/x')->curlResolveEntry())->toBe('shop.example.com:443:93.184.215.14,[2606:2800:21f:cb07:6820:80da:af6b:8b2c]')
        ->and($guard->inspectUrl('https://shop.example.com:8080/x')->curlResolveEntry())->toBe('shop.example.com:8080:93.184.215.14,[2606:2800:21f:cb07:6820:80da:af6b:8b2c]')
        ->and($guard->inspectUrl('https://8.8.8.8/')->curlResolveEntry())->toBeNull()
        ->and($guard->inspectUrl('https://dev-shop.test/')->allowListed)->toBeTrue()
        ->and($guard->inspectUrl('https://dev-shop.test/')->curlResolveEntry())->toBeNull();
});

test('the allow-list skips the check for its hosts only', function (): void {
    $guard = outboundGuard(['dev-shop.test' => ['127.0.0.1'], 'woo.lan.test' => ['192.168.1.20'], 'other.test' => ['127.0.0.1']], ['dev-shop.test', '*.lan.test']);

    expect($guard->isPublicUrl('https://dev-shop.test/'))->toBeTrue()
        ->and($guard->isPublicUrl('https://woo.lan.test/'))->toBeTrue()
        ->and($guard->isPublicUrl('https://other.test/'))->toBeFalse()
        ->and($guard->isPublicUrl('https://127.0.0.1/'))->toBeFalse();
});

test('the allow-list from the configuration is honoured in the local environment only', function (): void {
    config(['security.outbound.allowed_hosts' => ['dev-shop.test']]);
    app()->instance(HostResolver::class, new StaticHostResolver(['dev-shop.test' => ['127.0.0.1']]));

    expect(app(OutboundUrlGuard::class)->isPublicUrl('https://dev-shop.test/'))->toBeFalse();

    app()->detectEnvironment(fn (): string => 'local');

    expect(app(OutboundUrlGuard::class)->isPublicUrl('https://dev-shop.test/'))->toBeTrue();

    app()->detectEnvironment(fn (): string => 'production');

    expect(app(OutboundUrlGuard::class)->isPublicUrl('https://dev-shop.test/'))->toBeFalse();

    app()->detectEnvironment(fn (): string => 'testing');
});

test('a protected client checks every request, pins the addresses and never follows redirects', function (): void {
    app()->instance(HostResolver::class, new StaticHostResolver(['internal.example.com' => ['10.0.0.8']]));
    $seen = [];

    Http::fake(function ($request, array $options) use (&$seen) {
        $seen[] = $options;

        return Http::response(['ok' => true], 302, ['Location' => 'https://169.254.169.254/']);
    });

    $guard = app(OutboundUrlGuard::class);
    $response = $guard->protect(Http::acceptJson())->get('https://shop.example.com/api');

    expect($response->status())->toBe(302)
        ->and($seen)->toHaveCount(1)
        ->and($seen[0]['allow_redirects'])->toBeFalse()
        ->and($seen[0]['curl'][CURLOPT_RESOLVE])->toBe(['shop.example.com:443:'.StaticHostResolver::DEFAULT_ADDRESS]);

    expect(fn () => $guard->protect(Http::acceptJson())->get('https://internal.example.com/api'))->toThrow(UnsafeOutboundUrlException::class)
        ->and(fn () => $guard->protect(Http::acceptJson(), fn (UnsafeOutboundUrlException $e) => new RuntimeException('mapped'))->get('https://127.1/'))->toThrow(RuntimeException::class, 'mapped');

    expect($seen)->toHaveCount(1);
});

it('refuses plain http by default, even for a public host', function (): void {
    $guard = outboundGuard(['shop.example.com' => ['93.184.215.14']]);

    expect(fn () => $guard->inspectUrl('http://shop.example.com/api'))->toThrow(UnsafeOutboundUrlException::class)
        ->and($guard->isPublicUrl('http://shop.example.com/api'))->toBeFalse()
        ->and($guard->isPublicUrl('https://shop.example.com/api'))->toBeTrue();
});
