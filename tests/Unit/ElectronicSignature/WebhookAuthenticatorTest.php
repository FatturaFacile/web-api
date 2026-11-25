<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use Modules\ElectronicSignature\Services\WebhookAuthenticator;

beforeEach(function (): void {
    Cache::flush();
});

function esigWebhookError(callable $call): ?SignatureException
{
    try {
        $call();
    } catch (SignatureException $exception) {
        return $exception;
    }

    return null;
}

it('accepts a correctly signed event once', function (): void {
    $payload = '{"event":"completed"}';
    $headers = WebhookAuthenticator::sign($payload, 'secret-1');

    app(WebhookAuthenticator::class)->assertAuthentic($payload, $headers, 'secret-1', 'namirial', 'evt-1');

    expect(true)->toBeTrue();
});

it('rejects a wrong secret, a missing signature and a tampered body', function (): void {
    $service = app(WebhookAuthenticator::class);
    $payload = '{"event":"completed"}';
    $headers = WebhookAuthenticator::sign($payload, 'secret-1');

    expect(esigWebhookError(fn () => $service->assertAuthentic($payload, $headers, 'other-secret', 'namirial', 'evt-a'))?->errorCode())->toBe('webhook_unauthenticated')
        ->and(esigWebhookError(fn () => $service->assertAuthentic($payload, [], 'secret-1', 'namirial', 'evt-b'))?->errorCode())->toBe('webhook_unauthenticated')
        ->and(esigWebhookError(fn () => $service->assertAuthentic('{"event":"declined"}', $headers, 'secret-1', 'namirial', 'evt-c'))?->errorCode())->toBe('webhook_unauthenticated')
        ->and(esigWebhookError(fn () => $service->assertAuthentic($payload, $headers, null, 'namirial', 'evt-d'))?->errorCode())->toBe('webhook_unauthenticated');
});

it('rejects an event signed too long ago', function (): void {
    $payload = '{"event":"completed"}';
    $headers = WebhookAuthenticator::sign($payload, 'secret-1', time() - 3600);

    $error = esigWebhookError(fn () => app(WebhookAuthenticator::class)->assertAuthentic($payload, $headers, 'secret-1', 'namirial', 'evt-stale'));

    expect($error?->errorCode())->toBe('webhook_stale')->and($error?->status())->toBe(401);
});

it('rejects the same event delivered twice, per namespace', function (): void {
    $service = app(WebhookAuthenticator::class);
    $payload = '{"event":"completed"}';
    $headers = WebhookAuthenticator::sign($payload, 'secret-1');

    $service->assertAuthentic($payload, $headers, 'secret-1', 'namirial', 'evt-replay');

    $error = esigWebhookError(fn () => $service->assertAuthentic($payload, $headers, 'secret-1', 'namirial', 'evt-replay'));

    expect($error?->errorCode())->toBe('webhook_replayed')->and($error?->status())->toBe(409);

    // The same id under another provider is a different event.
    $service->assertAuthentic($payload, $headers, 'secret-1', 'infocert', 'evt-replay');
});
