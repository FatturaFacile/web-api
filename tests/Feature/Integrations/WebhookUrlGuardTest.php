<?php

declare(strict_types=1);

use App\Services\Security\OutboundUrlGuard;
use Modules\Integrations\Webhooks\Exceptions\UnsafeWebhookUrl;
use Modules\Integrations\Webhooks\WebhookUrlGuard;
use Tests\Support\StaticHostResolver;

function webhookGuard(array $records = []): WebhookUrlGuard
{
    return new WebhookUrlGuard(new OutboundUrlGuard(new StaticHostResolver($records)));
}

test('a public https URL is accepted, with the port rule', function (string $url): void {
    expect(webhookGuard()->assertSafe($url)->host)->not->toBe('');
})->with([
    'default port' => 'https://hooks.example.com/path?x=1',
    'port 443' => 'https://hooks.example.com:443/x',
    'high port' => 'https://hooks.example.com:8443/x',
    'public ipv4 literal' => 'https://8.8.8.8/x',
    'public ipv6 literal' => 'https://[2606:4700:4700::1111]/x',
]);

test('the SSRF cases are refused', function (string $url): void {
    expect(fn () => webhookGuard()->assertSafe($url))->toThrow(UnsafeWebhookUrl::class);
})->with([
    'plain http' => 'http://hooks.example.com/x',
    'ftp' => 'ftp://hooks.example.com/x',
    'no scheme' => 'hooks.example.com/x',
    'credentials' => 'https://user:pass@hooks.example.com/x',
    'user only' => 'https://user@hooks.example.com/x',
    'port 22' => 'https://hooks.example.com:22/x',
    'port 80' => 'https://hooks.example.com:80/x',
    'localhost' => 'https://localhost/x',
    'localhost subdomain' => 'https://app.localhost/x',
    'internal suffix' => 'https://db.internal/x',
    'loopback' => 'https://127.0.0.1/x',
    'loopback high' => 'https://127.255.255.254/x',
    'private 10' => 'https://10.0.0.5/x',
    'private 172' => 'https://172.16.5.4/x',
    'private 172 upper' => 'https://172.31.255.255/x',
    'private 192' => 'https://192.168.1.1/x',
    'link local' => 'https://169.254.1.1/x',
    'cloud metadata' => 'https://169.254.169.254/latest/meta-data/',
    'carrier grade nat' => 'https://100.64.0.1/x',
    'this network' => 'https://0.0.0.0/x',
    'multicast' => 'https://224.0.0.1/x',
    'reserved' => 'https://240.0.0.1/x',
    'broadcast' => 'https://255.255.255.255/x',
    'documentation' => 'https://192.0.2.1/x',
    'ipv6 loopback' => 'https://[::1]/x',
    'ipv6 unspecified' => 'https://[::]/x',
    'ipv6 unique local' => 'https://[fd00:ec2::254]/x',
    'ipv6 fc00' => 'https://[fc00::1]/x',
    'ipv6 link local' => 'https://[fe80::1]/x',
    'ipv4 mapped loopback' => 'https://[::ffff:127.0.0.1]/x',
    'ipv4 mapped metadata' => 'https://[::ffff:a9fe:a9fe]/x',
    'nat64 loopback' => 'https://[64:ff9b::7f00:1]/x',
    '6to4' => 'https://[2002:7f00:1::]/x',
    'decimal ip' => 'https://2130706433/x',
    'hex ip' => 'https://0x7f.1/x',
    'short ip' => 'https://127.1/x',
    'octal ip' => 'https://0177.0.0.1/x',
]);

test('a host whose DNS answer includes a private address is refused, even next to a public one', function (): void {
    $guard = webhookGuard([
        'rebind.example.com' => ['93.184.215.14', '10.0.0.8'],
        'metadata.example.com' => ['169.254.169.254'],
        'v6.example.com' => ['2606:4700:4700::1111', '::1'],
        'nothing.example.com' => [],
    ]);

    foreach (['rebind', 'metadata', 'v6'] as $name) {
        expect(fn () => $guard->assertSafe("https://{$name}.example.com/x"))->toThrow(UnsafeWebhookUrl::class);
    }

    expect($guard->assertSafe('https://ok.example.com/x')->addresses)->toBe([StaticHostResolver::DEFAULT_ADDRESS]);
});

test('a host that does not resolve is refused', function (): void {
    $guard = new WebhookUrlGuard(new OutboundUrlGuard(new StaticHostResolver([], null)));

    expect(fn () => $guard->assertSafe('https://nowhere.example.com/x'))->toThrow(UnsafeWebhookUrl::class);
});
