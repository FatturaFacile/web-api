<?php

declare(strict_types=1);

use App\Services\Security\FieldEncryptionContext;
use App\Services\Security\FieldEncryptionService;
use App\Services\Security\PiiMasker;

function fieldKek(): string
{
    return base64_encode(str_repeat('k', 32));
}

function ctx(string $tenant = 't1', string $field = 'iban'): FieldEncryptionContext
{
    return new FieldEncryptionContext($tenant, 'contact', '42', $field);
}

it('round-trips a value with AES-256-GCM', function (): void {
    $svc = new FieldEncryptionService(fieldKek(), 1);
    $cipher = $svc->encrypt('CH9300762011623852957', ctx());

    expect($cipher)->toStartWith('ff1:1:')->not->toContain('CH93')
        ->and($svc->decrypt($cipher, ctx()))->toBe('CH9300762011623852957');
});

it('rejects ciphertext moved to another tenant or field (AAD binding)', function (): void {
    $svc = new FieldEncryptionService(fieldKek(), 1);
    $cipher = $svc->encrypt('secret', ctx());

    expect(fn () => $svc->decrypt($cipher, ctx('t2')))->toThrow(RuntimeException::class)
        ->and(fn () => $svc->decrypt($cipher, ctx('t1', 'email')))->toThrow(RuntimeException::class);
});

it('detects a tampered ciphertext', function (): void {
    $svc = new FieldEncryptionService(fieldKek(), 1);
    $cipher = $svc->encrypt('secret', ctx());
    [$p, $v, $b] = explode(':', $cipher, 3);
    $raw = base64_decode($b);
    $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\x01";

    expect(fn () => $svc->decrypt("$p:$v:".base64_encode($raw), ctx()))->toThrow(RuntimeException::class);
});

it('rotates to a new DEK version and keeps old versions readable', function (): void {
    $v1 = new FieldEncryptionService(fieldKek(), 1);
    $v2 = new FieldEncryptionService(fieldKek(), 2);
    $old = $v1->encrypt('secret', ctx());

    expect($v2->decrypt($old, ctx()))->toBe('secret');

    $new = $v2->rotate($old, ctx());

    expect($v2->versionOf($new))->toBe(2)
        ->and($v2->decrypt($new, ctx()))->toBe('secret')
        ->and($v2->rotate($new, ctx()))->toBe($new);
});

it('produces deterministic, tenant-scoped blind indexes', function (): void {
    $svc = new FieldEncryptionService(fieldKek(), 1);

    expect($svc->blindIndex('CH93 0076', 't1', 'iban'))->toBe($svc->blindIndex('ch930076', 't1', 'iban'))
        ->and($svc->blindIndex('CH930076', 't1', 'iban'))->not->toBe($svc->blindIndex('CH930076', 't2', 'iban'))
        ->and($svc->blindIndex('CH930076', 't1', 'iban'))->not->toBe($svc->blindIndex('CH930076', 't1', 'email'));
});

it('refuses to run without a valid master key', function (): void {
    expect(fn () => (new FieldEncryptionService('short', 1))->encrypt('x', ctx()))->toThrow(RuntimeException::class);
});

it('masks sensitive values', function (): void {
    $m = new PiiMasker;

    expect($m->iban('CH9300762011623854567'))->toBe('CH93 **** **** **** 4567')
        ->and($m->taxId('RSSMRA80A01H501U'))->toBe('RSSMRA**********')
        ->and($m->email('mario@example.com'))->toBe('m****@example.com');
});
