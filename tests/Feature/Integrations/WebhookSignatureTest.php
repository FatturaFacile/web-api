<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Modules\Integrations\Webhooks\WebhookSigner;

beforeEach(function (): void {
    $this->secret = 'whsec_'.Str::random(40);
    $this->body = json_encode(['id' => (string) Str::uuid7(), 'type' => 'invoicing.invoice.posted', 'data' => ['total_gross' => 122.0]]);
});

test('the header carries the timestamp and the HMAC-SHA256 of "timestamp.body"', function (): void {
    $header = (new WebhookSigner)->header(1731312000, $this->body, [$this->secret]);

    expect($header)->toBe('t=1731312000,v1='.hash_hmac('sha256', '1731312000.'.$this->body, $this->secret));
});

test('a receiver verifies a genuine delivery', function (): void {
    $now = time();
    $header = (new WebhookSigner)->header($now, $this->body, [$this->secret]);

    expect(WebhookSigner::verify($header, $this->body, $this->secret, 300, $now))->toBeTrue();
});

test('verification fails for a wrong secret, a tampered body, a stale timestamp and a malformed header', function (): void {
    $now = time();
    $header = (new WebhookSigner)->header($now, $this->body, [$this->secret]);

    expect(WebhookSigner::verify($header, $this->body, $this->secret.'x', 300, $now))->toBeFalse()
        ->and(WebhookSigner::verify($header, $this->body.' ', $this->secret, 300, $now))->toBeFalse()
        ->and(WebhookSigner::verify($header, $this->body, $this->secret, 300, $now + 301))->toBeFalse()
        ->and(WebhookSigner::verify($header, $this->body, $this->secret, 300, $now - 301))->toBeFalse()
        ->and(WebhookSigner::verify('v1=abc', $this->body, $this->secret, 300, $now))->toBeFalse()
        ->and(WebhookSigner::verify("t={$now}", $this->body, $this->secret, 300, $now))->toBeFalse()
        ->and(WebhookSigner::verify('', $this->body, $this->secret, 300, $now))->toBeFalse();
});

test('while a rotation overlaps, the header holds one signature per secret and either secret verifies', function (): void {
    $now = time();
    $previous = 'whsec_'.Str::random(40);
    $header = (new WebhookSigner)->header($now, $this->body, [$this->secret, $previous]);

    expect(substr_count($header, 'v1='))->toBe(2)
        ->and(WebhookSigner::verify($header, $this->body, $this->secret, 300, $now))->toBeTrue()
        ->and(WebhookSigner::verify($header, $this->body, $previous, 300, $now))->toBeTrue()
        ->and(WebhookSigner::verify($header, $this->body, 'whsec_'.Str::random(40), 300, $now))->toBeFalse();
});
