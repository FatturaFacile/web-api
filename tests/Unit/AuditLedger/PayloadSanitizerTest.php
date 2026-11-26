<?php

declare(strict_types=1);

use Enterprise\AuditLedger\Domain\Services\PayloadSanitizer;
use Enterprise\AuditLedger\Domain\Services\Pseudonymizer;
use Enterprise\AuditLedger\Domain\ValueObjects\PseudonymToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AuditLedgerFixtures;

beforeEach(function (): void {
    $this->tenantId = (string) Str::uuid7();
    $this->pseudonymizer = new Pseudonymizer(AuditLedgerFixtures::keyring());
    $this->sanitizer = PayloadSanitizer::fromConfig($this->pseudonymizer);
});

test('secrets are never recorded, not even pseudonymised', function (string $key): void {
    $clean = $this->sanitizer->sanitize($this->tenantId, 'App\\Models\\User', 'u1', [$key => 'top-secret-value-123']);

    expect($clean[$key])->toBe(PayloadSanitizer::REDACTED)
        ->and(DB::table('audit_subject_map')->where('tenant_id', $this->tenantId)->count())->toBe(0);
})->with([
    'password', 'password_confirmation', 'current_password', 'new_password', 'secret', 'client_secret', 'token', 'remember_token',
    'api_key', 'access_token', 'refresh_token', 'private_key', 'otp', 'otp_code', 'two_factor_secret', 'two_factor_recovery_codes',
    'auth_code', 'cvv', 'card_number', 'pin', 'Password', 'API-KEY', 'stripe_secret', 'webhook_token', 'password_hash', 'sms_otp',
]);

test('names that merely contain a secret word are not redacted', function (string $key): void {
    $clean = $this->sanitizer->sanitize($this->tenantId, 'App\\Models\\Order', 'o1', [$key => 'Via Roma 1']);

    expect($clean[$key])->toBe('Via Roma 1');
})->with(['shipping_address', 'shipping_pincode', 'mapping_id', 'spinning_wheel', 'tokens_count', 'passport_office', 'opinion']);

test('nested secrets are redacted at every depth', function (): void {
    $clean = $this->sanitizer->sanitize($this->tenantId, 'App\\Models\\Setting', 's1', [
        'smtp' => ['host' => 'mail.example.test', 'password' => 'x', 'nested' => ['api_key' => 'y']],
    ]);

    expect($clean['smtp']['host'])->toBe('mail.example.test')
        ->and($clean['smtp']['password'])->toBe(PayloadSanitizer::REDACTED)
        ->and($clean['smtp']['nested']['api_key'])->toBe(PayloadSanitizer::REDACTED);
});

test('the fiscal code and the other personal identifiers are replaced by a pseudonym token', function (string $key): void {
    $clean = $this->sanitizer->sanitize($this->tenantId, 'App\\Models\\Contact', 'c1', [$key => 'RSSMRA80A01H501U']);

    expect(PseudonymToken::looksLikeToken($clean[$key]))->toBeTrue()
        ->and(json_encode($clean))->not->toContain('RSSMRA80A01H501U');
})->with(['fiscal_code', 'codice_fiscale', 'tax_code', 'ssn', 'avs_number', 'iban', 'qr_iban', 'date_of_birth']);

test('the personal attributes of a model are pseudonymised too', function (): void {
    $clean = $this->sanitizer->sanitize($this->tenantId, 'App\\Models\\Contact', 'c1', ['first_name' => 'Mario', 'city' => 'Lugano'], ['first_name']);

    expect(PseudonymToken::looksLikeToken($clean['first_name']))->toBeTrue()->and($clean['city'])->toBe('Lugano');
});

test('the same value of the same subject always gets the same token', function (): void {
    $one = $this->sanitizer->sanitize($this->tenantId, 'App\\Models\\Contact', 'c1', ['fiscal_code' => 'RSSMRA80A01H501U']);
    $two = $this->sanitizer->sanitize($this->tenantId, 'App\\Models\\Contact', 'c1', ['fiscal_code' => 'RSSMRA80A01H501U']);
    $other = $this->sanitizer->sanitize($this->tenantId, 'App\\Models\\Contact', 'c2', ['fiscal_code' => 'RSSMRA80A01H501U']);

    expect($one['fiscal_code'])->toBe($two['fiscal_code'])->and($other['fiscal_code'])->not->toBe($one['fiscal_code'])
        ->and(DB::table('audit_subject_map')->where('tenant_id', $this->tenantId)->count())->toBe(2);
});

test('a pseudonym resolves to the readable value until the subject is erased', function (): void {
    $clean = $this->sanitizer->sanitize($this->tenantId, 'App\\Models\\Contact', 'c1', ['fiscal_code' => 'RSSMRA80A01H501U']);
    $token = $clean['fiscal_code'];

    expect($this->pseudonymizer->resolveMany($this->tenantId, [$token]))->toBe([$token => 'RSSMRA80A01H501U']);

    expect($this->pseudonymizer->erase($this->tenantId, 'App\\Models\\Contact', 'c1'))->toBe(1)
        ->and($this->pseudonymizer->resolveMany($this->tenantId, [$token]))->toBe([$token => Pseudonymizer::ERASED_LABEL]);
});

test('the readable value is stored encrypted', function (): void {
    $this->sanitizer->sanitize($this->tenantId, 'App\\Models\\Contact', 'c1', ['fiscal_code' => 'RSSMRA80A01H501U']);

    $stored = (string) DB::table('audit_subject_map')->where('tenant_id', $this->tenantId)->value('display_name');

    expect($stored)->not->toContain('RSSMRA80A01H501U')->and($stored)->not->toBe('');
});

test('a mapping of another tenant is never resolved', function (): void {
    $clean = $this->sanitizer->sanitize($this->tenantId, 'App\\Models\\Contact', 'c1', ['fiscal_code' => 'RSSMRA80A01H501U']);

    expect($this->pseudonymizer->resolveMany((string) Str::uuid7(), [$clean['fiscal_code']]))
        ->toBe([$clean['fiscal_code'] => Pseudonymizer::ERASED_LABEL]);
});

test('tokens are found in and replaced inside a nested document', function (): void {
    $token = (string) $this->pseudonymizer->valueToken($this->tenantId, 'App\\Models\\Contact', 'c1', 'email', 'mario@example.test');
    $document = ['old' => [], 'new' => ['email' => $token, 'list' => [['x' => $token]]]];

    expect(Pseudonymizer::tokensIn($document))->toBe([$token, $token]);

    $resolved = $this->pseudonymizer->resolveMany($this->tenantId, Pseudonymizer::tokensIn($document));

    expect(Pseudonymizer::applyTo($document, $resolved))->toBe(['old' => [], 'new' => ['email' => 'mario@example.test', 'list' => [['x' => 'mario@example.test']]]]);
});
