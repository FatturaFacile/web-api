<?php

declare(strict_types=1);

use Enterprise\AuditLedger\Domain\Enums\ActorType;
use Enterprise\AuditLedger\Domain\Enums\AuditCategory;
use Enterprise\AuditLedger\Domain\ValueObjects\AuditAction;
use Enterprise\AuditLedger\Domain\ValueObjects\ChainHash;
use Enterprise\AuditLedger\Domain\ValueObjects\ClientContext;
use Enterprise\AuditLedger\Domain\ValueObjects\PseudonymToken;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\AuditLedger\Support\AuditIds;
use Enterprise\AuditLedger\Support\AuditRetentionPolicy;
use Enterprise\AuditLedger\Support\LoginThrottle;
use Enterprise\AuditLedger\Support\ResourceTypes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

test('the ip address is truncated by policy before it is kept', function (?string $ip, ?string $expected): void {
    expect(ClientContext::truncateIp($ip))->toBe($expected);
})->with([
    ['203.0.113.77', '203.0.113.0'],
    ['2001:db8:abcd:12:ffff:ffff:ffff:ffff', '2001:db8:abcd::'],
    ['not an ip', null],
    ['', null],
    [null, null],
]);

test('the ip truncation follows the configured prefix', function (): void {
    config(['audit_ledger.ip_truncation.ipv4_prefix' => 16]);

    expect(ClientContext::truncateIp('203.0.113.77'))->toBe('203.0.0.0');
});

test('the user agent is cleaned and bounded', function (): void {
    expect(ClientContext::sanitizeUserAgent("Mozilla/5.0\n\tX11"))->toBe('Mozilla/5.0 X11')
        ->and(strlen((string) ClientContext::sanitizeUserAgent(str_repeat('a', 400))))->toBe(255)
        ->and(ClientContext::sanitizeUserAgent('  '))->toBeNull();
});

test('only the channel and the request id are hashed', function (): void {
    $context = new ClientContext('mobile', 'req-1', '203.0.113.0', 'agent');

    expect($context->hashedPart())->toBe(['channel' => 'mobile', 'request_id' => 'req-1']);
});

test('an action is upper case, bounded and classified read only or not', function (): void {
    expect(AuditAction::make(' login_failed ')->value)->toBe('LOGIN_FAILED')
        ->and(strlen(AuditAction::make(str_repeat('x', 80))->value))->toBe(50)
        ->and(AuditAction::make('VIEWED')->isReadOnly())->toBeTrue()
        ->and(AuditAction::make('INVOICE_DOWNLOADED')->isReadOnly())->toBeTrue()
        ->and(AuditAction::make('UPDATED')->isReadOnly())->toBeFalse()
        ->and(AuditAction::make('SDI_SENT')->isReadOnly())->toBeFalse()
        ->and(fn () => AuditAction::make('   '))->toThrow(InvalidArgumentException::class);
});

test('the category is inferred from the module and the action', function (string $action, string $module, AuditCategory $expected): void {
    expect(AuditCategory::infer($action, $module))->toBe($expected);
})->with([
    ['LOGIN_FAILED', 'AUTH', AuditCategory::Security],
    ['access.security.account_locked', 'ACCESS', AuditCategory::Security],
    ['VIEWED', 'CRM', AuditCategory::Access],
    ['EXPORTED', 'ANALYTICS', AuditCategory::Access],
    ['TENANT_RESTORED', 'SYSTEM', AuditCategory::Platform],
    ['AUDIT_INTEGRITY_VERIFIED', 'AUDIT', AuditCategory::Platform],
    ['CREATED', 'CRM', AuditCategory::Crud],
    ['RESTORED', 'CRM', AuditCategory::Crud],
    ['SDI_SENT', 'SDI', AuditCategory::Business],
]);

test('stored actor types map to the four actor types', function (): void {
    expect(ActorType::fromStored('system_job'))->toBe(ActorType::Job)
        ->and(ActorType::fromStored('user'))->toBe(ActorType::User)
        ->and(ActorType::fromStored('api_key'))->toBe(ActorType::ApiKey)
        ->and(ActorType::fromStored(null))->toBe(ActorType::System);
});

test('a chain hash is 64 lower case hex characters', function (): void {
    expect(ChainHash::fromString(str_repeat('a', 64))->value)->toBe(str_repeat('a', 64))
        ->and(ChainHash::zero()->value)->toBe(str_repeat('0', 64))
        ->and(ChainHash::zero()->equals(str_repeat('0', 64)))->toBeTrue()
        ->and(fn () => ChainHash::fromString('XYZ'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ChainHash::fromString(str_repeat('A', 64)))->toThrow(InvalidArgumentException::class);
});

test('a pseudonym token is recognised by its shape', function (): void {
    $token = PseudonymToken::fromDigest(hash('sha256', 'x'));

    expect(PseudonymToken::looksLikeToken((string) $token))->toBeTrue()
        ->and(PseudonymToken::looksLikeToken('~pk:short'))->toBeFalse()
        ->and(PseudonymToken::looksLikeToken('plain text'))->toBeFalse()
        ->and(PseudonymToken::looksLikeToken(12))->toBeFalse()
        ->and(fn () => PseudonymToken::fromString('plain'))->toThrow(InvalidArgumentException::class);
});

test('a resource id that is not a uuid becomes a stable name based uuid', function (): void {
    $uuid = '0194f7a0-1111-7000-8000-000000000001';

    expect(AuditIds::uuidFor($uuid))->toBe($uuid)
        ->and(AuditIds::uuidFor('42'))->toBe(AuditIds::uuidFor('42'))
        ->and(AuditIds::uuidFor('42'))->not->toBe(AuditIds::uuidFor('43'))
        ->and(AuditIds::uuidFor('42'))->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

test('the short resource types of the api map to the classes of the ledger', function (): void {
    expect(ResourceTypes::candidates('Invoice'))->toBe(['Invoice', 'App\\Models\\Invoice'])
        ->and(ResourceTypes::candidates('App\\Models\\Contact'))->toBe(['App\\Models\\Contact'])
        ->and(ResourceTypes::module('Invoice'))->toBe('invoicing')
        ->and(ResourceTypes::module('App\\Models\\Contact'))->toBe('crm')
        ->and(ResourceTypes::module('Nope'))->toBeNull();
});

test('a configured retention below the legal minimum is raised to it', function (): void {
    config(['audit_ledger.retention.years.accounting' => 3, 'audit_ledger.retention.years.access' => 0.1, 'audit_ledger.retention.years.security' => 5]);
    $policy = new AuditRetentionPolicy;

    expect($policy->years('accounting'))->toBe(10.0)
        ->and($policy->years('access'))->toBe(0.5)
        ->and($policy->years('security'))->toBe(5.0)
        ->and($policy->minimumYears('accounting'))->toBe(10.0);
});

test('accounting modules keep their entries ten years whatever the category', function (): void {
    $policy = new AuditRetentionPolicy;

    expect($policy->keyFor('crud', 'GeneralLedger'))->toBe('accounting')
        ->and($policy->keyFor('business', 'SDI'))->toBe('accounting')
        ->and($policy->keyFor('security', 'AUTH'))->toBe('security')
        ->and($policy->expiresAt(Carbon::parse('2027-01-01', 'UTC'), 'accounting')->year)->toBe(2037);
});

test('an entry is not expirable before its period ends or without an anchored checkpoint', function (): void {
    $policy = new AuditRetentionPolicy;
    $entry = new AuditLedgerEntry([
        'tenant_id' => '0194f7a0-1111-7000-8000-000000000001',
        'category' => 'access',
        'module_code' => 'CRM',
        'action' => 'VIEWED',
        'sequence' => 5,
        'event_timestamp' => Carbon::parse('2020-01-01', 'UTC'),
    ]);

    // Old enough, but no checkpoint anchors the chain up to this sequence yet.
    expect($policy->isExpirable($entry, Carbon::parse('2027-01-01', 'UTC')))->toBeFalse();

    $recent = new AuditLedgerEntry(['tenant_id' => '0194f7a0-1111-7000-8000-000000000001', 'category' => 'access', 'module_code' => 'CRM', 'action' => 'VIEWED', 'sequence' => 6, 'event_timestamp' => Carbon::parse('2026-12-01', 'UTC')]);

    expect($policy->isExpirable($recent, Carbon::parse('2027-01-01', 'UTC')))->toBeFalse();
});

test('the login throttle blocks an address after the configured failures and expires', function (): void {
    config(['audit_ledger.login_throttle.max_failures' => 3, 'audit_ledger.login_throttle.enabled' => true]);
    Cache::flush();
    $throttle = new LoginThrottle;

    expect($throttle->hit('198.51.100.7'))->toBeFalse()
        ->and($throttle->hit('198.51.100.7'))->toBeFalse()
        ->and($throttle->isBlocked('198.51.100.7'))->toBeFalse()
        ->and($throttle->hit('198.51.100.7'))->toBeTrue()
        ->and($throttle->isBlocked('198.51.100.7'))->toBeTrue()
        ->and($throttle->isBlocked('198.51.100.8'))->toBeFalse();
});

test('the login throttle can be switched off', function (): void {
    config(['audit_ledger.login_throttle.max_failures' => 1, 'audit_ledger.login_throttle.enabled' => false]);
    Cache::flush();
    $throttle = new LoginThrottle;

    expect($throttle->hit('198.51.100.9'))->toBeFalse()->and($throttle->isBlocked('198.51.100.9'))->toBeFalse();
});
