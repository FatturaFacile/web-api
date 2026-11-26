<?php

declare(strict_types=1);

use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\Security\LoginLockout;

test('without the progressive lockout every lock has the base duration', function (): void {
    foreach ([1, 2, 5, 12] as $streak) {
        expect(LoginLockout::minutesFor(15, $streak, false, 1440))->toBe(15);
    }
});

test('the progressive lockout doubles the lock at every consecutive lockout', function (): void {
    expect(array_map(fn (int $streak): int => LoginLockout::minutesFor(10, $streak, true, 100000), [1, 2, 3, 4, 5]))->toBe([10, 20, 40, 80, 160]);
});

test('the lock never exceeds the cap, and never falls below the base', function (): void {
    expect(LoginLockout::minutesFor(10, 4, true, 50))->toBe(50)
        ->and(LoginLockout::minutesFor(10, 2, true, 15))->toBe(15)
        ->and(LoginLockout::minutesFor(30, 3, true, 10))->toBe(30)
        ->and(LoginLockout::minutesFor(30, 1, true, 10))->toBe(30);
});

test('a huge streak cannot overflow the lock', function (): void {
    expect(LoginLockout::minutesFor(15, 500, true, 1440))->toBe(1440)
        ->and(LoginLockout::minutesFor(0, 1, true, 60))->toBe(1);
});

test('the status of an audit action follows the failure convention', function (): void {
    foreach (['LOGIN_FAILED', 'SSO_LOGIN_FAILED', 'LOGIN_LOCKED', 'PASSWORD_LOGIN_DENIED_SSO_ONLY', 'PASSWORD_EXPIRED_LOGIN_DENIED', 'PASSWORD_RESET_TOKEN_EXPIRED', 'PASSWORD_RESET_ATTEMPTS_EXCEEDED', 'EMAIL_VERIFICATION_TOKEN_INVALID', 'TENANT_ACCESS_REJECTED', 'ACCESS.SECURITY.LOGIN_FAILED', 'ACCESS.SECURITY.ACCOUNT_LOCKED'] as $action) {
        expect(RegistrationAudit::statusOf($action))->toBe('failure', $action);
    }

    foreach (['LOGIN_SUCCEEDED', 'LOGIN_UNLOCKED', 'PASSWORD_CHANGED', 'SESSION_IDLE_EXPIRED', 'SESSION_REVOKED', 'SSO_LOGIN_STARTED', 'SCIM_USER_DEACTIVATED', 'TWO_FACTOR_ENABLED', 'ACCESS.SECURITY.PASSKEY_REGISTERED'] as $action) {
        expect(RegistrationAudit::statusOf($action))->toBe('success', $action);
    }
});

test('the auth method follows from the action and the protocol', function (): void {
    expect(RegistrationAudit::authMethodOf('SSO_LOGIN_SUCCEEDED', ['protocol' => 'saml']))->toBe('sso_saml')
        ->and(RegistrationAudit::authMethodOf('SSO_LOGIN_FAILED', ['protocol' => 'oidc']))->toBe('sso_oidc')
        ->and(RegistrationAudit::authMethodOf('SSO_LOGIN_FAILED', []))->toBe('sso')
        ->and(RegistrationAudit::authMethodOf('LOGIN_FAILED'))->toBe('password')
        ->and(RegistrationAudit::authMethodOf('PASSWORD_CHANGED'))->toBe('password')
        ->and(RegistrationAudit::authMethodOf('LOGIN_UNLOCKED'))->toBeNull()
        ->and(RegistrationAudit::authMethodOf('SCIM_USER_CREATED'))->toBeNull();
});
