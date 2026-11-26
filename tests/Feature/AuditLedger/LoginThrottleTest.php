<?php

declare(strict_types=1);

use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\AuditLedger\Support\LoginThrottle;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cache;
use Tests\Support\AuditLedgerFixtures;

beforeEach(function (): void {
    Cache::flush();
    config(['audit_ledger.login_throttle.enabled' => true, 'audit_ledger.login_throttle.max_failures' => 3]);
});

/**
 * Fires failed logins as seen from an address.
 */
function failedLoginsFrom(string $ip, int $times): void
{
    app('request')->server->set('REMOTE_ADDR', $ip);

    foreach (range(1, $times) as $ignored) {
        event(new Failed('web', null, ['email' => 'nobody@example.test']));
    }
}

test('each failed login is a security entry of the chain', function (): void {
    failedLoginsFrom('198.51.100.7', 2);

    $entries = AuditLedgerEntry::query()->where('action', 'LOGIN_FAILED')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->every(fn (AuditLedgerEntry $e): bool => $e->category === 'security' && $e->ip_address === '198.51.100.0'))->toBeTrue()
        ->and(AuditLedgerEntry::query()->where('action', 'LOGIN_RATE_LIMITED')->count())->toBe(0);
});

test('repeated failures from one address block it temporarily and leave a security entry', function (): void {
    failedLoginsFrom('198.51.100.7', 3);

    $limited = AuditLedgerEntry::query()->where('action', 'LOGIN_RATE_LIMITED')->get();

    expect($limited)->toHaveCount(1)
        ->and($limited->first()->category)->toBe('security')
        ->and($limited->first()->new_values['max_failures'])->toBe(3)
        ->and(app(LoginThrottle::class)->isBlocked('198.51.100.7'))->toBeTrue();

    // A fourth failure inside the block does not write a second block entry.
    failedLoginsFrom('198.51.100.7', 1);

    expect(AuditLedgerEntry::query()->where('action', 'LOGIN_RATE_LIMITED')->count())->toBe(1);
});

test('a blocked address is refused at the login with 429 while another address is not', function (): void {
    failedLoginsFrom('198.51.100.7', 3);

    $blocked = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'whatever-value']);

    expect($blocked->status())->toBe(429)
        ->and($blocked->headers->get('Retry-After'))->not->toBeNull()
        ->and($blocked->json('title'))->toBe('Too Many Requests');

    $other = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'whatever-value']);

    expect($other->status())->not->toBe(429);
});

test('the block expires with its cache entry', function (): void {
    failedLoginsFrom('198.51.100.7', 3);
    expect(app(LoginThrottle::class)->isBlocked('198.51.100.7'))->toBeTrue();

    Cache::flush();

    expect(app(LoginThrottle::class)->isBlocked('198.51.100.7'))->toBeFalse();
});

test('the throttle can be switched off', function (): void {
    config(['audit_ledger.login_throttle.enabled' => false]);

    failedLoginsFrom('198.51.100.7', 5);

    expect(AuditLedgerEntry::query()->where('action', 'LOGIN_RATE_LIMITED')->count())->toBe(0)
        ->and(app(LoginThrottle::class)->isBlocked('198.51.100.7'))->toBeFalse();
});

test('a successful login of the user is recorded with a truncated address', function (): void {
    ['tenant' => $tenant, 'user' => $user] = AuditLedgerFixtures::tenant('Login Tenant');
    app('request')->server->set('REMOTE_ADDR', '198.51.100.77');

    event(new Login('web', $user, false));

    $entry = AuditLedgerEntry::query()->where('tenant_id', (string) $tenant->id)->where('action', 'LOGIN_SUCCESS')->firstOrFail();

    expect($entry->category)->toBe('security')->and($entry->ip_address)->toBe('198.51.100.0')->and($entry->actor_user_id)->toBe((string) $user->id);
});
